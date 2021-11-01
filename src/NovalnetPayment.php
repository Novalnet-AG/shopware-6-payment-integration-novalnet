<?php

/**
 * Novalnet payment plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to Novalnet End User License Agreement
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @category    Novalnet
 * @package     NovalnetPayment
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

declare(strict_types=1);

namespace Novalnet\NovalnetPayment;

use Novalnet\NovalnetPayment\Installer\PaymentMethodInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * NovalnetPayment Class.
 */
class NovalnetPayment extends Plugin
{
    /**
     * Builds a `NovalnetPayment` plugin.
     *
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }

    /**
     * Plugin installation process
     *
     * @param InstallContext $installContext
     */
    public function postInstall(InstallContext $installContext): void
    {
        (new PaymentMethodInstaller($this->container, $installContext->getContext()))->install();
        parent::install($installContext);
    }

    /**
     * Plugin update process
     *
     * @param UpdateContext $updateContext
     */
    public function postUpdate(UpdateContext $updateContext): void
    {
        (new PaymentMethodInstaller($this->container, $updateContext->getContext()))->update();
        parent::postUpdate($updateContext);
    }

    /**
     * Plugin uninstall process
     *
     * @param UninstallContext $uninstallContext
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        $installer = new PaymentMethodInstaller($this->container, $uninstallContext->getContext());
        $installer->uninstall();
        if (!$uninstallContext->keepUserData()) {
            $installer->removeConfiguration();
        }
        parent::uninstall($uninstallContext);
    }

    /**
     * Plugin activate process
     *
     * @param ActivateContext $activateContext
     */
    public function activate(ActivateContext $activateContext): void
    {
        (new PaymentMethodInstaller($this->container, $activateContext->getContext()))->activate();
        parent::activate($activateContext);
    }

    /**
     * Plugin deactivate process
     *
     * @param DeactivateContext $deactivateContext
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        (new PaymentMethodInstaller($this->container, $deactivateContext->getContext()))->deactivate();
        parent::deactivate($deactivateContext);
    }
}
