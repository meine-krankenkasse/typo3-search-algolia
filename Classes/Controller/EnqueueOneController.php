<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\FileHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

use function is_array;

/**
 * EnqueueOneController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class EnqueueOneController
{
    /**
     * @var EventDispatcherInterface
     */
    private readonly EventDispatcherInterface $eventDispatcher;

    /**
     * @var ModuleTemplateFactory
     */
    private readonly ModuleTemplateFactory $moduleTemplateFactory;

    /**
     * @var ResourceFactory
     */
    private readonly ResourceFactory $resourceFactory;

    /**
     * @var ResponseFactory
     */
    private readonly ResponseFactory $responseFactory;

    /**
     * @var FileHandler
     */
    private readonly FileHandler $fileHandler;

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param ModuleTemplateFactory    $moduleTemplateFactory
     * @param ResourceFactory          $resourceFactory
     * @param ResponseFactory          $responseFactory
     * @param FileHandler              $fileHandler
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        ModuleTemplateFactory $moduleTemplateFactory,
        ResourceFactory $resourceFactory,
        ResponseFactory $responseFactory,
        FileHandler $fileHandler,
    ) {
        $this->eventDispatcher       = $eventDispatcher;
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->resourceFactory       = $resourceFactory;
        $this->responseFactory       = $responseFactory;
        $this->fileHandler           = $fileHandler;
    }

    /**
     * Enqueues a single file.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws InvalidFileException
     * @throws ResourceDoesNotExistException
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $identifier = $this->getTarget($request);
        $file       = $this->resourceFactory->retrieveFileOrFolderObject($identifier);

        if (!$file instanceof FileInterface) {
            throw new InvalidFileException(
                'Referenced target "' . $identifier . '" could not be resolved to a valid file',
                1744874148
            );
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
     * Returns the target identifier.
     *
     * @param ServerRequestInterface $request
     *
     * @return string
     */
    private function getTarget(ServerRequestInterface $request): string
    {
        $parsedBody = $request->getParsedBody();

        if (is_array($parsedBody) && isset($parsedBody['target'])) {
            return $parsedBody['target'];
        }

        return $request->getQueryParams()['target'] ?? '';
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
