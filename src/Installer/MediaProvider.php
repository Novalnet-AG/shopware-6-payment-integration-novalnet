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

namespace Novalnet\NovalnetPayment\Installer;

use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

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
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;
    
    /**
     * Constructs a `MediaProvider`
     *
     * @param MediaService $mediaService
     * @param EntityRepositoryInterface $mediaRepository
     */
    public function __construct(MediaService $mediaService, EntityRepositoryInterface $mediaRepository)
    {
        $this->mediaService	   = $mediaService;
        $this->mediaRepository = $mediaRepository;
    }
    
    /**
     * Get Media ID
     *
     * @param string $paymentMethod
     * @param Context $context
     *
     * @return string|null
     */
    public function getMediaId(string $paymentMethod, Context $context): ?string
    {
        $fileName = $paymentMethod . '-icon';
        
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('fileName', $fileName));
        
        $icons = $this->mediaRepository->search($criteria, $context);
        
        // Return the already existing file in the smae name.
        if ($icons->count() && $icons->first() != null) {
            return $icons->first()->getId();
        }
        if(in_array($paymentMethod, ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetinvoiceinstalment']))
        {
			$paymentMethod = 'novalnetinvoice';
		} elseif (in_array($paymentMethod, ['novalnetsepa', 'novalnetsepaguarantee', 'novalnetsepainstalment']))
		{
			$paymentMethod = 'novalnetsepa';
		}
        // Insert media file to library.
        $file		= file_get_contents(dirname(__DIR__, 1).'/Resources/public/storefront/assets/img/'.$paymentMethod.'.png');
        $mediaId = '';
        if ($file) {
            $mediaId = $this->mediaService->saveFile($file, 'png', 'image/png', $fileName, $context, 'Novalnet Payment - Icons', null, false);
        }
        return $mediaId;
    }
}
