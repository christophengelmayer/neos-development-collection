<?php

namespace Neos\ContentGraph\Domain\Repository;

/*
 * This file is part of the Neos.ContentGraph package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */
use Neos\ContentRepository\Domain as ContentRepository;
use Neos\ContentRepository\Domain\Context\DimensionCombination;
use Neos\ContentRepository\Domain\Projection\Content as ContentProjection;
use Neos\Flow\Annotations as Flow;

/**
 * The abstract content graph
 */
abstract class AbstractContentGraph implements ContentProjection\ContentGraphInterface
{
    /**
     * @Flow\Inject
     * @var DimensionCombination\Repository\ContentDimensionValueCombinationRepository
     */
    protected $dimensionValueCombinationRepository;

    /**
     * @Flow\Inject
     * @var ContentRepository\Projection\Workspace\WorkspaceFinder
     */
    protected $workspaceFinder;

    /**
     * @var array|ContentProjection\ContentSubgraphInterface[]
     */
    protected $subgraphs;


    final public function initializeObject()
    {
        foreach ($this->workspaceFinder->findAll() as $workspace) {
            $contentStreamIdentifier = null;
            foreach ($this->dimensionValueCombinationRepository->findAll() as $dimensionValueCombination) {
                $subgraphIdentifier = new ContentRepository\ValueObject\SubgraphIdentifier($contentStreamIdentifier, $dimensionValueCombination);
                $this->subgraphs[$subgraphIdentifier->getHash()] = $this->createSubgraph($subgraphIdentifier);
            }
        }
    }

    /**
     * @param ContentRepository\ValueObject\SubgraphIdentifier $subgraphIdentifier
     * @return ContentProjection\ContentSubgraphInterface|null
     */
    final public function getSubgraphByIdentifier(ContentRepository\ValueObject\SubgraphIdentifier $subgraphIdentifier)
    {
        return $this->getSubgraphByIdentityHash($subgraphIdentifier->getHash());
    }

    /**
     * @param string $identityHash
     * @return ContentProjection\ContentSubgraphInterface|null
     */
    final public function getSubgraphByIdentityHash(string $identityHash)
    {
        return $this->subgraphs[$identityHash] ?? null;
    }

    /**
     * @return array|ContentProjection\ContentSubgraphInterface[]
     */
    final public function getSubgraphs(): array
    {
        return $this->subgraphs;
    }

    /**
     * @param ContentRepository\ValueObject\SubgraphIdentifier $subgraphIdentifier
     * @return ContentProjection\ContentSubgraphInterface
     */
    abstract protected function createSubgraph(ContentRepository\ValueObject\SubgraphIdentifier $subgraphIdentifier): ContentProjection\ContentSubgraphInterface;
}
