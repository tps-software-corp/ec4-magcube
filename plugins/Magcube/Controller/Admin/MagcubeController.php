<?php

namespace Plugin\Magcube\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\Magcube\Form\Type\Admin\MagentoSearch;
use Plugin\Magcube\Repository\ConfigRepository;
use Plugin\Magcube\Repository\MagcubeRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Eccube\Entity\Master\ProductStatus;
use Eccube\Entity\Product;
use Eccube\Entity\ProductCategory;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ProductImage;
use Eccube\Entity\ProductStock;
use Eccube\Entity\ProductTag;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\CategoryRepository;
use Eccube\Repository\Master\PageMaxRepository;
use Eccube\Repository\Master\ProductStatusRepository;
use Eccube\Repository\ProductClassRepository;
use Eccube\Repository\ProductImageRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\TagRepository;
use Eccube\Repository\TaxRuleRepository;


class MagcubeController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var MagcubeRepository
     */
    protected $magcubeRepository;

    /**
     * @var ProductClassRepository
     */
    protected $productClassRepository;

    /**
     * @var ProductImageRepository
     */
    protected $productImageRepository;

    /**
     * @var TaxRuleRepository
     */
    protected $taxRuleRepository;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var BaseInfo
     */
    protected $BaseInfo;

    /**
     * @var PageMaxRepository
     */
    protected $pageMaxRepository;

    /**
     * @var ProductStatusRepository
     */
    protected $productStatusRepository;

    /**
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * ProductController constructor.
     *
     * @param ProductClassRepository $productClassRepository
     * @param ProductImageRepository $productImageRepository
     * @param TaxRuleRepository $taxRuleRepository
     * @param CategoryRepository $categoryRepository
     * @param ProductRepository $productRepository
     * @param BaseInfoRepository $baseInfoRepository
     * @param ProductStatusRepository $productStatusRepository
     */
    public function __construct(
        ProductClassRepository $productClassRepository,
        ProductImageRepository $productImageRepository,
        TaxRuleRepository $taxRuleRepository,
        CategoryRepository $categoryRepository,
        ProductRepository $productRepository,
        BaseInfoRepository $baseInfoRepository,
        ProductStatusRepository $productStatusRepository,
        ConfigRepository $configRepository,
        MagcubeRepository $magcubeRepository
    ) {
        $this->productClassRepository = $productClassRepository;
        $this->productImageRepository = $productImageRepository;
        $this->taxRuleRepository = $taxRuleRepository;
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->BaseInfo = $baseInfoRepository->get();
        $this->productStatusRepository = $productStatusRepository;
        $this->configRepository = $configRepository;
        $this->magcubeRepository = $magcubeRepository;
    }

    /**
     * @Route("/%eccube_admin_route%/magcube/index", name="magcube_admin_index")
     * @Template("@Magcube/admin/index.twig")
     */
    public function index(Request $request)
    {
        if (!$this->configRepository->get()) {
            $this->addWarning('magcube.errors.must_set_config', 'admin');
            return $this->redirectToRoute('magcube_admin_config');
        }   
        $form = $this->createForm(MagentoSearch::class);
        $form->handleRequest($request);
        $products = [];
        $sku = '';
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $products = $this->magcubeRepository->search($data['sku']);
            $sku = $data['sku'];
        }
        return [
            'form' => $form->createView(),
            'products' => $products,
            'sku' => $sku
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/magcube/fetch", name="magcube_admin_fetch")
     * //- @Template("@Magcube/admin/index.twig")
     */
    public function fetch(Request $request)
    {
        $name = $request->get('name');
        $price = $request->get('price');
        $description = $request->get('description');
        $images = $request->get('images');
        // Create new product 
        $Product = new Product();
        $ProductClass = new ProductClass();
        $ProductStatus = $this->productStatusRepository->find(ProductStatus::DISPLAY_SHOW);
        $Product
            ->addProductClass($ProductClass)
            ->setStatus($ProductStatus)
            ->setName($name)
            ->setDescriptionDetail($description);
        $ProductClass
            ->setVisible(true)
            ->setStockUnlimited(true)
            ->setPrice01($price)
            ->setPrice02($price)
            ->setProduct($Product);
        $this->entityManager->persist($Product);
        $ProductStock = new ProductStock();
        $ProductClass->setProductStock($ProductStock);
        $ProductStock->setProductClass($ProductClass);
        // 個別消費税
        if ($this->BaseInfo->isOptionProductTaxRule()) {
            if ($ProductClass->getTaxRate() !== null) {
                if ($ProductClass->getTaxRule()) {
                    $ProductClass->getTaxRule()->setTaxRate($ProductClass->getTaxRate());
                } else {
                    $taxrule = $this->taxRuleRepository->newTaxRule();
                    $taxrule->setTaxRate($ProductClass->getTaxRate());
                    $taxrule->setApplyDate(new \DateTime());
                    $taxrule->setProduct($Product);
                    $taxrule->setProductClass($ProductClass);
                    $ProductClass->setTaxRule($taxrule);
                }

                $ProductClass->getTaxRule()->setTaxRate($ProductClass->getTaxRate());
            } else {
                if ($ProductClass->getTaxRule()) {
                    $this->taxRuleRepository->delete($ProductClass->getTaxRule());
                    $ProductClass->setTaxRule(null);
                }
            }
        }
        $this->entityManager->persist($ProductClass);
        // 在庫情報を作成
        if (!$ProductClass->isStockUnlimited()) {
            $ProductStock->setStock($ProductClass->getStock());
        } else {
            // 在庫無制限時はnullを設定
            $ProductStock->setStock(null);
        }
        $this->entityManager->persist($ProductStock);

        // 画像の登録
        foreach ($images as $image) {
            $add_image = basename($image);
            file_put_contents($this->eccubeConfig['eccube_save_image_dir'].'/'.$add_image, file_get_contents($image));
            $ProductImage = new \Eccube\Entity\ProductImage();
            $ProductImage
                ->setFileName($add_image)
                ->setProduct($Product)
                ->setSortNo(1);
            $Product->addProductImage($ProductImage);
            $this->entityManager->persist($ProductImage);
        }
        $this->entityManager->flush();

        return $this->json(['success' => true], 200);
    }
}
