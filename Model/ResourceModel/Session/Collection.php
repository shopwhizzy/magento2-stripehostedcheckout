<?php

namespace ShopWhizzy\StripeHostedCheckout\Model\ResourceModel\Session;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use ShopWhizzy\StripeHostedCheckout\Model\Session as SessionModel;
use ShopWhizzy\StripeHostedCheckout\Model\ResourceModel\Session as SessionResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(SessionModel::class, SessionResource::class);
    }
}
