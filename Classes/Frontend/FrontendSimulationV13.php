<?php

declare(strict_types=1);

namespace Causal\Oidc\Frontend;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;

/**
 * Basic idea taken from \TYPO3\CMS\Redirects\Service\RedirectService::bootFrontendController
 */
class FrontendSimulationV13 implements FrontendSimulationInterface
{
    public function getCObj(ServerRequestInterface $originalRequest): ContentObjectRenderer
    {
        $siteMatcher = GeneralUtility::makeInstance(SiteMatcher::class);
        $routeResult = $siteMatcher->matchRequest($originalRequest);
        if ($routeResult instanceof SiteRouteResult) {
            $site = $routeResult->getSite();
            if ($site instanceof Site) {
                try {
                    $pageInformationFactory = GeneralUtility::makeInstance(PageInformationFactory::class);
                    $frontendTypoScriptFactory = GeneralUtility::makeInstance(FrontendTypoScriptFactory::class);
                    $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
                    /** @var PhpFrontend $cache */
                    $cache = $cacheManager->getCache('typoscript');
                    $queryParams = [];

                    $context = GeneralUtility::makeInstance(Context::class);
                    $context->setAspect('frontend.preview', new PreviewAspect());
                    $cacheInstruction = $originalRequest->getAttribute('frontend.cache.instruction', new CacheInstruction());
                    $originalRequest = $originalRequest->withAttribute('frontend.cache.instruction', $cacheInstruction);
                    $queryParamsFromRequest = $originalRequest->getQueryParams();
                    $mergedQueryParams = array_merge($queryParams, $queryParamsFromRequest);
                    $originalRequest = $originalRequest->withQueryParams($mergedQueryParams);
                    $pageArguments = new PageArguments($site->getRootPageId(), '0', []);
                    $originalRequest = $originalRequest->withAttribute('routing', $pageArguments);
                    $pageInformation = $pageInformationFactory->create($originalRequest);
                    $originalRequest = $originalRequest->withAttribute('frontend.page.information', $pageInformation);
                    $controller = GeneralUtility::makeInstance(TypoScriptFrontendController::class);
                    $controller->initializePageRenderer($originalRequest);
                    $expressionMatcherVariables = $this->getExpressionMatcherVariables($site, $originalRequest, $controller);
                    $frontendTypoScript = $frontendTypoScriptFactory->createSettingsAndSetupConditions(
                        $site,
                        $pageInformation->getSysTemplateRows(),
                        // $originalRequest does not contain site ...
                        $expressionMatcherVariables,
                        $cache,
                    );
                    // Note, that we need the full TypoScript setup array, which is required for links created by
                    // DatabaseRecordLinkBuilder. This should be kept in mind when TSFE will be removed in v14.
                    $frontendTypoScript = $frontendTypoScriptFactory->createSetupConfigOrFullSetup(
                        true,
                        $frontendTypoScript,
                        $site,
                        $pageInformation->getSysTemplateRows(),
                        $expressionMatcherVariables,
                        '0',
                        $cache,
                        null
                    );

                    $newRequest = $originalRequest->withAttribute('frontend.typoscript', $frontendTypoScript);
                    $controller->newCObj($newRequest);
                    $GLOBALS['TSFE'] = $controller;

                    return $controller->cObj;
                } catch (RouteNotFoundException) {
                }
            }
        }
        throw new \InvalidArgumentException('Failed to build TypoScript');
    }

    public function cleanupTSFE(): void
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $context->unsetAspect('language');
        $context->unsetAspect('typoscript');
        $context->unsetAspect('frontend.preview');
        unset($GLOBALS['TSFE']);
    }

    protected function getExpressionMatcherVariables(
        SiteInterface $site,
        ServerRequestInterface $request,
        TypoScriptFrontendController $controller
    ): array {
        $pageInformation = $request->getAttribute('frontend.page.information');
        $topDownRootLine = $pageInformation->getRootLine();
        $localRootline = $pageInformation->getLocalRootLine();
        ksort($topDownRootLine);
        return [
            'request' => $request,
            'pageId' => $pageInformation->getId(),
            'page' => $pageInformation->getPageRecord(),
            'fullRootLine' => $topDownRootLine,
            'localRootLine' => $localRootline,
            'site' => $site,
            'siteLanguage' => $request->getAttribute('language'),
            'tsfe' => $controller,
        ];
    }
}
