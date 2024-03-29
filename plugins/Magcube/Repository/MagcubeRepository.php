<?php

namespace Plugin\Magcube\Repository;

use Eccube\Repository\AbstractRepository;
use Plugin\Magcube\Entity\Config;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Plugin\Magcube\Repository\ConfigRepository;

/**
 * ConfigRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class MagcubeRepository
{
    private $client;
    private $session;
    private $url;
    private $username;
    private $password;
    /**
     * ConfigRepository constructor.
     *
     * @param RegistryInterface $registry
     */
    public function __construct(RegistryInterface $registry, ConfigRepository $configRepository)
    {
        $config = $configRepository->get();
        if ($config) {
            $this->url = $config->getSoapUrl();
            $this->username = $config->getUsername();
            $this->password = $config->getPassword();
        }
    }

    protected function _login() 
    {
        $this->client = new \SoapClient($this->url);
        // If somestuff requires api authentication,
        // then get a session token
        if (!empty($_SESSION['soap_session']) && false) {
            $this->session = $_SESSION['soap_session'];
        } else {
            $this->session = $this->client->login($this->username, $this->password);
            $_SESSION['soap_session'] = $this->session;
        }

        if (!empty($_SESSION['soapcookies'])) {
            foreach($_SESSION['soapcookies'] AS $name=>$value) {
                if (is_array($value)) {
                    foreach($value AS $k=>$v) {
                        $this->client->__setCookie($name[$k], $v);
                    }
                } else {
                    $this->client->__setCookie($name, $value);
                }
            }
        }
    }
    public function getInfo($product_id) 
    {
        $this->_login();
        $result = $this->client->catalogProductInfo($this->session, $product_id);
        $result->images = $this->client->catalogProductAttributeMediaList($this->session, $product_id);
        $_SESSION['soapcookies'] = $this->client->_cookies;
        return $this->_format($result);
    }

    public function search($sku)
    {
        $this->_login();
        $filter = array(
            'complex_filter' => array(
                array('key' => 'type', 'value' => array('key' => 'eq', 'value' => 'configurable')),
                array('key' => 'sku', 'value' => array('key' => 'like', 'value' => '%'.$sku.'%'))
            )
        );
        $result = $this->client->catalogProductList($this->session, $filter);
        array_slice($result, 8); 
        foreach($result as $index => $p) {
            $result[$index]->images = $this->client->catalogProductAttributeMediaList($this->session, $p->product_id);
            $result[$index]->info = $this->client->catalogProductInfo($this->session, $p->product_id);
        }
        $_SESSION['soapcookies'] = $this->client->_cookies;
        return $this->_format($result);
    }

    protected function _format($result)
    {
        if (!is_array($result)) {
            $result = [$result];
        }
        foreach($result as $index => $p) {
            $price = substr($p->sku, strlen($p->sku) - 3, strlen($p->sku));
            $result[$index]->price = (int) ($price . '000');
        }
        if (count($result) == 1 ) {
            return $result[0];
        }
        return $result;
    }
}
