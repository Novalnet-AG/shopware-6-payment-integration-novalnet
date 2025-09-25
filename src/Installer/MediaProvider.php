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
 * @category  Novalnet
 * @package   NovalnetPayment
 * @copyright Copyright (c) Novalnet
 * @license   https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MediaProvider Class.
 */
class MediaProvider
{
    /**
     * @var MediaService
     */
    private $mediaService;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * Constructs a `MediaProvider`
     *
     * @param MediaService $mediaService
     * @param ContainerInterface $container
     */
    public function __construct(MediaService $mediaService, ContainerInterface $container)
    {
        $this->mediaService = $mediaService;
        $this->connection = $container->get(Connection::class);
    }

    /**
     * Retrieves the media id for the given payment method, or inserts a new media item if it doesn't exist.
     *
     * @param string $paymentMethod
     * @param Context $context
     *
     * @return string|null
     */
    public function getMediaId(string $paymentMethod, Context $context): ?string
    {
        $fileName = $paymentMethod . '-icon';

        $iconId = $this->connection->fetchOne('SELECT `id` FROM `media` WHERE file_name = "' . $fileName . '"');

        // Return the already existing file in the same name.
        if ($iconId) {
            return Uuid::fromBytesToHex($iconId);
        }

        // Insert media file to library.
        $file = file_get_contents(\dirname(__DIR__, 1) . '/Resources/public/storefront/assets/img/' . $paymentMethod . '.png');
        $mediaId = '';
        if ($file) {
            $mediaId = $this->mediaService->saveFile($file, 'png', 'image/png', $fileName, $context, 'Novalnet Payment - Icons', null, false);
        }

        return $mediaId;
    }
}
