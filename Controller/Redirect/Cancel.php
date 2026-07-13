<?php

namespace ShopWhizzy\StripeHostedCheckout\Controller\Redirect;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use ShopWhizzy\StripeHostedCheckout\Model\Session as SessionModel;
use ShopWhizzy\StripeHostedCheckout\Model\SessionFactory;
use ShopWhizzy\StripeHostedCheckout\Model\ResourceModel\Session as SessionResource;

class Cancel extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly SessionFactory $sessionFactory,
        private readonly SessionResource $sessionResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $sessionId = (string) $this->getRequest()->getParam('session_id');

        if ($sessionId) {
            $mapping = $this->sessionFactory->create();
            $this->sessionResource->loadBySessionId($mapping, $sessionId);
            if ($mapping->getId() && $mapping->getStatus() === SessionModel::STATUS_PENDING) {
                $mapping->setStatus(SessionModel::STATUS_CANCELLED);
                $this->sessionResource->save($mapping);
            }
        }

        $this->messageManager->addNoticeMessage(__('Checkout was cancelled. Your cart has been saved.'));

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/cart');

        return $resultRedirect;
    }
}
