<?php

declare(strict_types=1);

namespace Sitegeist\ForceFeed\Command;

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Utility\Environment;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use OpenAI\Client;
use Sitegeist\ForceFeed\Domain\JsonlRecord;
use Sitegeist\ForceFeed\Domain\JsonlRecordCollection;

class ExportCommandController extends CommandController
{
    public function __construct(
        private readonly ContentContextFactory $contentContextFactory,
        private readonly SiteRepository $siteRepository,
        private readonly Environment $environment,
        private readonly Client $client,
    ) {
        parent::__construct();
    }

    public function testClientCommand(): void
    {
        $assistants = $this->client->assistants()->list();
        foreach ($assistants->data as $assistant) {
            $this->outputLine($assistant->name);
        }
    }


    public function jsonlCommand(string $siteNodeName, string $dimensions): void
    {
        $records = new JsonlRecordCollection(...$this->traverseSubtree($this->getContentContext($siteNodeName, $dimensions)->getCurrentSiteNode()));

        echo (string)$records;
    }

    public function uploadJsonlCommand(string $siteNodeName, string $dimensions): void
    {
        $records = new JsonlRecordCollection(...$this->traverseSubtree($this->getContentContext($siteNodeName, $dimensions)->getCurrentSiteNode()));

        $client = $this->client();

        $path = $this->environment->getPathToTemporaryDirectory() . '/' . $siteNodeName . '::' . md5($dimensions) . '.jsonl';
        file_put_contents($path, (string)$records);

        $client->files()->upload([
            'file' => fopen($path, 'r'),
            'purpose' => 'assistants'
        ]);
    }

    private function getContentContext(string $siteNodeName, string $dimensions): ContentContext
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

        return $contentContext;
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
