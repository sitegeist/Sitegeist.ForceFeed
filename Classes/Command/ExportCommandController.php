<?php

declare(strict_types=1);

namespace Sitegeist\ForceFeed\Command;

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Sitegeist\ForceFeed\Domain\JsonlRecord;
use Sitegeist\ForceFeed\Domain\JsonlRecordCollection;

class ExportCommandController extends CommandController
{
    public function __construct(
        private readonly ContentContextFactory $contentContextFactory,
        private readonly SiteRepository $siteRepository,
    ) {
        parent::__construct();
    }

    public function jsonlCommand(string $siteNodeName, string $dimensions): void
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);
        if (!$site) {
            throw new \Exception('Unknown site ' . $siteNodeName);
        }
        $dimensionValues = \json_decode($dimensions, true, 512, JSON_THROW_ON_ERROR);

        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'dimensions' => $dimensionValues,
            'targetDimensions' => array_map(
                fn (array $dimensionValues): string => array_shift($dimensionValues),
                $dimensionValues
            ),
            'currentSite' => $site
        ]);

        $records = new JsonlRecordCollection(...$this->traverseSubtree($contentContext->getCurrentSiteNode()));

        echo (string)$records;
    }

    /**
     * @return JsonlRecord[]
     */
    private function traverseSubtree(NodeInterface $documentNode): array
    {
        $documents = [];
        if (!$documentNode->getNodeType()->isOfType('Neos.Neos:Shortcut')) {
            $documents[] = $this->transformDocument($documentNode);
        }
        foreach ($documentNode->getChildNodes('Neos.Neos:Document') as $childDocument) {
            $documents = array_merge($documents, $this->traverseSubtree($childDocument));
        }

        return $documents;
    }

    private function transformDocument(NodeInterface $documentNode): JsonlRecord
    {
        $content = '';
        foreach ($documentNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $childNode) {
            $content .= ' ' . $this->extractContent($childNode);
        }

        return new JsonlRecord(
            $documentNode->getIdentifier(),
            new Uri('node://' . $documentNode->getIdentifier()),
            trim($content)
        );
    }

    private function extractContent(NodeInterface $contentNode): string
    {
        $content = '';

        if ($contentNode->getNodeType()->isOfType('Neos.Neos:ContentCollection')) {
            foreach ($contentNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $childNode) {
                $content .= $this->extractContent($childNode);
            }
        }
        if ($contentNode->getNodeType()->isOfType('Neos.Neos:Content')) {
            foreach ($contentNode->getNodeType()->getProperties() as $propertyName => $propertyConfiguration) {
                if (($propertyConfiguration['type'] ?? 'string') === 'string' && ($propertyConfiguration['ui']['inlineEditable'] ?? false) === true) {
                    $content .= ' ' . $contentNode->getProperty($propertyName);
                }
            }
        }

        return trim($content);
    }
}
