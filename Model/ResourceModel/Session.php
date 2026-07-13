<?php

namespace ShopWhizzy\StripeHostedCheckout\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Session extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('shopwhizzy_stripehostedcheckout_session', 'entity_id');
    }

    /**
     * Load a session record by Stripe Checkout Session ID.
     */
    public function loadBySessionId(\ShopWhizzy\StripeHostedCheckout\Model\Session $session, string $sessionId): void
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('session_id = ?', $sessionId);
        $data = $connection->fetchRow($select);
        if ($data) {
            $session->setData($data);
            $this->unserializeFields($session);
            $this->_afterLoad($session);
        }
    }
}
