<?php

namespace ShopWhizzy\StripeHostedCheckout\Model;

use Magento\Framework\Model\AbstractModel;
use ShopWhizzy\StripeHostedCheckout\Model\ResourceModel\Session as SessionResource;

class Session extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected function _construct()
    {
        $this->_init(SessionResource::class);
    }
}
