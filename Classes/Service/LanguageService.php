<?php

declare(strict_types=1);

namespace WebVision\WvDeepltranslate\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use WebVision\WvDeepltranslate\Domain\Repository\SettingsRepository;
use WebVision\WvDeepltranslate\Exception\LanguageIsoCodeNotFoundException;
use WebVision\WvDeepltranslate\Exception\LanguageRecordNotFoundException;

class LanguageService
{
    protected DeeplService $deeplService;

    protected SettingsRepository $settingsRepository;

    protected bool $siteLanguageMode = true;

    protected array $possibleLangMatches = [
        'deeplTargetLanguage',
        'hreflang',
        'iso-639-1',
    ];

    public function __construct(
        ?DeeplService $deeplService = null,
        ?SettingsRepository $settingsRepository = null
    ) {
        $this->deeplService = $deeplService ?? GeneralUtility::makeInstance(DeeplService::class);
        $this->settingsRepository = $settingsRepository ?? GeneralUtility::makeInstance(SettingsRepository::class);
        $typo3VersionArray = VersionNumberUtility::convertVersionStringToArray(
            VersionNumberUtility::getCurrentTypo3Version()
        );

        if (version_compare((string)$typo3VersionArray['version_main'], '11', '<')) {
            $this->siteLanguageMode = false;
        }
    }

    /**
     * @return array{site: Site, pageUid: int}|null
     */
    public function getCurrentSite(string $tableName, int $currentRecordId): ?array
    {
        if ($tableName === 'pages') {
            $pageId = $currentRecordId;
        } else {
            $currentPageRecord = BackendUtility::getRecord($tableName, $currentRecordId);
            $pageId = (int)$currentPageRecord['pid'];
        }
        try {
            return [
                'site' => GeneralUtility::makeInstance(SiteFinder::class)
                    ->getSiteByPageId($pageId),
                'pageUid' => $pageId,
            ];
        } catch (SiteNotFoundException $e) {
            return null;
        }
    }

    /**
     * @return array{uid: int, title: string, language_isocode: string}
     * @throws LanguageIsoCodeNotFoundException
     */
    public function getSourceLanguage(Site $currentSite): array
    {
        $sourceLanguageRecord = [
            'uid' => $currentSite->getDefaultLanguage()->getLanguageId(),
            'title' => $currentSite->getDefaultLanguage()->getTitle(),
            'language_isocode' => strtoupper($currentSite->getDefaultLanguage()->getTwoLetterIsoCode()),
        ];

        return $sourceLanguageRecord;
    }

    /**
     * @return array{uid: int, title: string, locale: string}
     * @throws LanguageRecordNotFoundException
     */
    public function getTargetLanguage(Site $currentSite, int $languageId): array
    {
        $languages = array_filter($currentSite->getConfiguration()['languages'], function ($value) use ($languageId) {
            if (!is_array($value)) {
                return false;
            }

            if ((int)$value['languageId'] === $languageId) {
                return true;
            }

            return false;
        });

        if (count($languages) === 0) {
            throw new LanguageRecordNotFoundException(
                sprintf(
                    'Language "%d" not found in SiteConfig "%s"',
                    $languageId,
                    $currentSite->getConfiguration()['websiteTitle']
                ),
                1676824459
            );
        }
        $language = reset($languages);

        return [
            'uid' => $language['languageId'] ?? 0,
            'title' => $language['title'],
            'locale' => $language['locale'],
        ];
    }

    /**
     * @return array{uid: int, title: string, language_isocode: string}
     * @throws LanguageRecordNotFoundException
     */
    private function getRecordFromSysLanguage(int $uid): array
    {
        $languageRecord = BackendUtility::getRecord('sys_language', $uid, 'uid,title,language_isocode');
        if ($languageRecord === null) {
            throw new LanguageRecordNotFoundException(
                sprintf('No language for record with uid "%d" found.', $uid),
                1676739761064
            );
        }
        $languageRecord['language_isocode'] = strtoupper($languageRecord['language_isocode']);

        return $languageRecord;
    }

    public function isSiteLanguageMode(): bool
    {
        return $this->siteLanguageMode;
    }
}
