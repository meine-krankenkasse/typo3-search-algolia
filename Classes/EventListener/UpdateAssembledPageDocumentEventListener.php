<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

use function is_array;

/**
 * Class UpdateAssembledPageDocumentEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class UpdateAssembledPageDocumentEventListener
{
    /**
     * @var ConfigurationManagerInterface
     */
    private ConfigurationManagerInterface $configurationManager;

    /**
     * @var SiteFinder
     */
    private SiteFinder $siteFinder;

    /**
     * @var ContentRepository
     */
    private ContentRepository $contentRepository;

    /**
     * Constructor.
     *
     * @param ConfigurationManagerInterface $configurationManager
     * @param SiteFinder                    $siteFinder
     * @param ContentRepository             $contentRepository
     */
    public function __construct(
        ConfigurationManagerInterface $configurationManager,
        SiteFinder $siteFinder,
        ContentRepository $contentRepository,
    ) {
        $this->configurationManager = $configurationManager;
        $this->siteFinder           = $siteFinder;
        $this->contentRepository    = $contentRepository;
    }

    /**
     * Invoke the event listener.
     *
     * @param AfterDocumentAssembledEvent $event
     */
    public function __invoke(AfterDocumentAssembledEvent $event): void
    {
        if (!($event->getIndexer() instanceof PageIndexer)) {
            return;
        }

        $document = $event->getDocument();
        $record   = $event->getRecord();
        $pageId   = $record['uid'];
        $site     = $this->getSite($pageId);

        // Set page related fields
        $document->setField(
            'site',
            $this->getSiteDomain($site)
        );

        if ($record['SYS_LASTCHANGED'] !== 0) {
            $document->setField(
                'changed',
                $record['SYS_LASTCHANGED']
            );
        }

        if ($site instanceof Site) {
            $document->setField(
                'url',
                $this->getPageUrl(
                    $site,
                    $pageId
                )
            );
        }

        if ($event->getIndexingService()->isIncludeContentElements()) {
            $document->setField(
                'content',
                $this->getPageContent($pageId)
            );
        }
    }

    /**
     * Returns the site identified by the given page ID.
     *
     * @param int $pageId
     *
     * @return SiteInterface
     */
    private function getSite(int $pageId): SiteInterface
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return new NullSite();
        }
    }

    /**
     * Returns the domain of the site identified by the given page ID.
     *
     * @param SiteInterface $site
     *
     * @return string
     */
    private function getSiteDomain(SiteInterface $site): string
    {
        return $site->getBase()->getHost();
    }

    /**
     * Returns the page URL.
     *
     * @param Site $site
     * @param int  $pageId
     *
     * @return string
     */
    private function getPageUrl(Site $site, int $pageId): string
    {
        return (string) $site
            ->getRouter()
            ->generateUri($pageId);
    }

    /**
     * Returns the page content or NULL if content is empty.
     *
     * @param int $pageId The UID of the page to be processed
     *
     * @return string|null
     *
     * @throws Exception
     */
    private function getPageContent(int $pageId): ?string
    {
        // Get configured fields
        $typoscriptConfiguration = $this->getTypoScriptConfiguration();
        $contentElementFields    = $typoscriptConfiguration['indexer'][ContentIndexer::TABLE]['fields'];

        if (!is_array($contentElementFields)) {
            return null;
        }

        $rows = $this->contentRepository
            ->findAllByPid(
                $pageId,
                array_keys($contentElementFields)
            );

        $content = '';

        foreach ($rows as $row) {
            foreach ($row as $fieldContent) {
                $content .= $fieldContent . "\n";
            }
        }

        $content = ContentExtractor::cleanHtml($content);

        return $content !== '' ? $content : null;
    }

    /**
     * Returns the TypoScript configuration of the extension.
     *
     * @return array<string, array<string, array<string, string|array<string, string>>>>
     */
    private function getTypoScriptConfiguration(): array
    {
        $typoscriptConfiguration = $this->configurationManager
            ->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
                Constants::EXTENSION_NAME
            );

        return GeneralUtility::removeDotsFromTS($typoscriptConfiguration)['module']['tx_typo3searchalgolia'];
    }
}
