<?php
namespace Neos\Neos\Http;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Context\Dimension;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Domain\ValueObject\DimensionSpacePoint;
use Neos\ContentRepository\Domain\ValueObject\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\RoutingComponent;

/**
 * The HTTP component for detecting the requested dimension space point
 */
final class DetectContentSubgraphComponent implements Http\Component\ComponentInterface
{
    /**
     * @Flow\Inject
     * @var Dimension\ContentDimensionSourceInterface
     */
    protected $dimensionSource;

    /**
     * @Flow\Inject
     * @var ContentDimensionDetection\ContentDimensionValueDetectorResolver
     */
    protected $contentDimensionPresetDetectorResolver;

    /**
     * @Flow\InjectConfiguration(path="contentDimensions.resolution.uriPathSegmentDelimiter")
     * @var string
     */
    protected $uriPathSegmentDelimiter;


    /**
     * @param Http\Component\ComponentContext $componentContext
     * @throws ContentDimensionDetection\Exception\InvalidContentDimensionValueDetectorException
     */
    public function handle(Http\Component\ComponentContext $componentContext)
    {
        $uriPathSegmentUsed = false;

        $existingParameters = $componentContext->getParameter(RoutingComponent::class, 'parameters') ?? RouteParameters::createEmpty();
        $parameters = $existingParameters
            ->withParameter('dimensionSpacePoint', $this->detectDimensionSpacePoint($componentContext, $uriPathSegmentUsed))
            ->withParameter('uriPathSegmentOffset', $uriPathSegmentUsed ? 1 : 0)
            ->withParameter('workspaceName', $this->detectWorkspaceName($componentContext) ?: WorkspaceName::forLive());

        $componentContext->setParameter(RoutingComponent::class, 'parameters', $parameters);
    }

    /**
     * @param Http\Component\ComponentContext $componentContext
     * @param bool $uriPathSegmentUsed
     * @return DimensionSpacePoint
     * @throws ContentDimensionDetection\Exception\InvalidContentDimensionValueDetectorException
     */
    protected function detectDimensionSpacePoint(Http\Component\ComponentContext $componentContext, bool &$uriPathSegmentUsed): DimensionSpacePoint
    {
        $coordinates = [];
        $path = $componentContext->getHttpRequest()->getUri()->getPath();

        /** @todo no more paths! */
        $isContextPath = NodePaths::isContextPath($path);
        $backendUriDimensionPresetDetector = new ContentDimensionDetection\BackendUriContentDimensionValueDetector();
        $dimensions = $this->dimensionSource->getContentDimensionsOrderedByPriority();
        $this->sortDimensionsByOffset($dimensions);
        $uriPathSegmentOffset = 0;
        foreach ($dimensions as $rawDimensionIdentifier => $contentDimension) {
            $detector = $this->contentDimensionPresetDetectorResolver->resolveContentDimensionValueDetector($contentDimension);

            $detectorOverrideOptions = $contentDimension->getConfigurationValue('resolution.options') ?? [];
            $resolutionMode = $contentDimension->getConfigurationValue('resolution.mode') ?? BasicContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT;
            if ($resolutionMode === BasicContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
                $detectorOverrideOptions['delimiter'] = $this->uriPathSegmentDelimiter;
                if (!isset($detectorOverrideOptions['offset'])) {
                    $detectorOverrideOptions['offset'] = $uriPathSegmentOffset;
                }
            }

            if ($isContextPath) {
                $dimensionValue = $backendUriDimensionPresetDetector->detectValue($contentDimension, $componentContext);
                if ($dimensionValue) {
                    $coordinates[$rawDimensionIdentifier] = (string)$dimensionValue;
                    if ($detector instanceof ContentDimensionDetection\UriPathSegmentContentDimensionValueDetector) {
                        // we might have to remove the uri path segment anyway
                        $dimensionValueByUriPathSegment = $detector->detectValue($contentDimension, $componentContext, $detectorOverrideOptions);
                        if ($dimensionValueByUriPathSegment) {
                            $uriPathSegmentUsed = true;
                        }
                    }
                    continue;
                }
            }

            $dimensionValue = $detector->detectValue($contentDimension, $componentContext, $detectorOverrideOptions);
            if ($dimensionValue) {
                $coordinates[$rawDimensionIdentifier] = (string)$dimensionValue;
                if ($resolutionMode === BasicContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
                    $uriPathSegmentUsed = true;
                    $uriPathSegmentOffset++;
                }
            } else {
                $allowEmptyValue = $detectorOverrideOptions['allowEmptyValue'] ?? false;
                if ($allowEmptyValue || $resolutionMode === BasicContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT && $path === '/') {
                    $coordinates[$rawDimensionIdentifier] = (string) $contentDimension->getDefaultValue();
                }
            }
        }

        return new DimensionSpacePoint($coordinates);
    }

    /**
     * @param array|Dimension\ContentDimension[] $dimensions
     * @return void
     */
    protected function sortDimensionsByOffset(array & $dimensions)
    {
        uasort($dimensions, function (Dimension\ContentDimension $dimensionA, Dimension\ContentDimension $dimensionB) {
            return ($dimensionA->getConfigurationValue('resolution.options.offset') ?: 0)
                <=> ($dimensionB->getConfigurationValue('resolution.options.offset') ?: 0);
        });
    }

    /**
     * @param Http\Component\ComponentContext $componentContext
     * @return WorkspaceName|null
     */
    protected function detectWorkspaceName(Http\Component\ComponentContext $componentContext): ?WorkspaceName
    {
        $requestPath = $componentContext->getHttpRequest()->getUri()->getPath();
        $requestPath = mb_substr($requestPath, mb_strrpos($requestPath, '/'));
        if ($requestPath !== '' && NodePaths::isContextPath($requestPath)) {
            $nodePathAndContext = NodePaths::explodeContextPath($requestPath);
            try {
                return new WorkspaceName($nodePathAndContext['workspaceName']);
            } catch (\InvalidArgumentException $exception) {
            }
        }

        return null;
    }
}
