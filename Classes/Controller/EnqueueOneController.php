<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\FileHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Traits\FileEligibilityTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Resource\Exception as ResourceException;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

use function is_array;
use function is_string;
use function preg_match;

/**
 * EnqueueOneController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class EnqueueOneController
{
    use FileEligibilityTrait;

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param ModuleTemplateFactory    $moduleTemplateFactory
     * @param ResourceFactory          $resourceFactory
     * @param ResponseFactory          $responseFactory
     * @param FileHandler              $fileHandler
     * @param ConnectionPool           $connectionPool        The connection pool to read the file records with
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ResourceFactory $resourceFactory,
        private ResponseFactory $responseFactory,
        private FileHandler $fileHandler,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Enqueues a single file.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $file = $this->findFile($this->getTarget($request));

        // The context menu only offers the entry to users who may edit the
        // metadata, the route itself has to enforce the same permissions. A
        // target that is not a file is answered the same way, so that the
        // answer does not tell whether a file exists.
        if (
            !($file instanceof FileInterface)
            || !$this->mayEditMetadata($file, $this->getBackendUser())
        ) {
            return $this->responseFactory
                ->createResponse(403);
        }

        $view        = $this->moduleTemplateFactory->create($request);
        $metadataUid = $this->fileHandler->getMetadataUid($file);

        if ($metadataUid !== false) {
            $this->eventDispatcher
                ->dispatch(
                    new DataHandlerRecordUpdateEvent(
                        'sys_file_metadata',
                        $metadataUid
                    )
                );

            $view->addFlashMessage(
                $this->translate(
                    'flash_message.success.message.enqueueOne',
                    [
                        $file->getName(),
                    ]
                ),
                $this->translate('flash_message.success.title'),
                ContextualFeedbackSeverity::INFO
            );
        }

        return $this->responseFactory
            ->createResponse();
    }

    /**
     * Returns the current backend user.
     *
     * @return BackendUserAuthentication The backend user of the request
     */
    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Returns the file for the identifier, or NULL if it cannot be resolved.
     *
     * Only the combined identifier of a file that is indexed already is resolved,
     * which is what the context menu sends for a file of the file list. Resolving
     * a path form for a file that is not indexed yet makes TYPO3 write the file
     * record before the permissions of the user are looked at.
     *
     * @param string $identifier The combined identifier of the target
     *
     * @return FileInterface|null The file, or NULL if the identifier names no indexed file
     */
    private function findFile(string $identifier): ?FileInterface
    {
        if (preg_match('#^(\d+):(/.*)\z#', $identifier, $matches) !== 1) {
            return null;
        }

        try {
            $storage = $this->resourceFactory->getStorageObject((int) $matches[1]);

            $fileRecord = $this->connectionPool
                ->getConnectionForTable('sys_file')
                ->select(
                    ['uid'],
                    'sys_file',
                    [
                        'storage'         => $storage->getUid(),
                        'identifier_hash' => $storage->hashFileIdentifier($matches[2]),
                    ]
                )
                ->fetchAssociative();

            if (!is_array($fileRecord)) {
                return null;
            }

            return $this->resourceFactory->getFileObject((int) $fileRecord['uid']);
        } catch (ResourceException|RuntimeException|InvalidArgumentException|DbalException) {
            // The identifier comes from the request, and an unknown storage ends in
            // an exception of the resource factory
            return null;
        }
    }

    /**
     * Returns the target identifier.
     *
     * @param ServerRequestInterface $request
     *
     * @return string
     */
    private function getTarget(ServerRequestInterface $request): string
    {
        $parsedBody = $request->getParsedBody();

        $target = (is_array($parsedBody) && isset($parsedBody['target']))
            ? $parsedBody['target']
            : ($request->getQueryParams()['target'] ?? '');

        return is_string($target) ? $target : '';
    }

    /**
     * Returns the translated language label for the given identifier.
     *
     * @param string                       $key
     * @param array<int|float|string>|null $arguments
     *
     * @return string
     */
    protected function translate(string $key, ?array $arguments = null): string
    {
        return LocalizationUtility::translate(
            $key,
            Constants::EXTENSION_NAME,
            $arguments
        ) ?? '';
    }
}
