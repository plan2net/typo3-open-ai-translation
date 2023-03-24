<?php

declare(strict_types=1);

namespace WebVision\WvDeepltranslate\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ModelTrainingDataExportCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Sync all glossaries to DeepL API')
            ->addArgument(
                'pageId',
                InputArgument::REQUIRED,
                'Page ID of root page'
            )
            ->addArgument(
                'sourceLanguageId',
                InputArgument::REQUIRED,
                'sys_language_uid of source language'
            )
            ->addArgument(
                'targetLanguageIds',
                InputArgument::REQUIRED,
                'sys_language_uids of target language'
            )
            ->addArgument(
                'outputDir',
                InputArgument::REQUIRED,
                'Output directory for Json files'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pageId = (int) $input->getArgument('pageId');
        $sourceLanguageId = (int) $input->getArgument('sourceLanguageId');
        $targetLanguageIds = GeneralUtility::trimExplode(',', $input->getArgument('targetLanguageIds'), true);
        $outputDir = $input->getArgument('outputDir');

        $content = [];
        $pages = $this->getAllSubpagesForPage($pageId, $targetLanguageIds);
        $siteConfig = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByRootPageId($pageId);
        $languages = $siteConfig->getAllLanguages();
        foreach ($pages as $page) {
            $ttContentQueryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tt_content');
            $result = $ttContentQueryBuilder->select('*')
                ->from('tt_content')
                ->where(
                    $ttContentQueryBuilder->expr()->eq('pid', $page),
                    $ttContentQueryBuilder->expr()->or(
                            $ttContentQueryBuilder->expr()->neq('header', "''"),
                            $ttContentQueryBuilder->expr()->neq('bodytext', "''"),
                    ),
                    $ttContentQueryBuilder->expr()->in('sys_language_uid',
                        array_merge($targetLanguageIds, [$sourceLanguageId]))
                )->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $uid = $row['uid'];
                if (0 !== $row['l18n_parent']) {
                    $uid = $row['l18n_parent'];
                }
                $content[$uid][$row['sys_language_uid']] = $row['header'] . " \n " . $row['bodytext'];
            }
        }
        $jsonContent = [];
        foreach ($content as $uid => $data) {
            foreach ($data as $language => $content) {
                if ($language !== $sourceLanguageId) {
                    $jsonContent[] = [
                        'prompt' => "Translate to " . $languages[$language]->getLocale() . ": \n " . $data[$sourceLanguageId],
                        'completion' => $content
                    ];
                }
            }
        }

        file_put_contents(rtrim($outputDir, '/') .'/export' . date('now') . '.json', json_encode($jsonContent));

        return Command::SUCCESS;
    }

    /**
     * @return int[] Returns the list of subpages (if any pages selected!)
     */
    public function getAllSubpagesForPage(
        int $id,
        array $sysLanguageUids
    ): array {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $result = $queryBuilder
            ->select('uid', 'pid', 'l10n_parent', 'sys_language_uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($id, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->neq(
                    'l10n_parent',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($sysLanguageUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery();

        $translatedSubPageIds = [];
        while ($row = $result->fetchAssociative()) {
            $translatedSubPageIds[] = (int) $row['l10n_parent'];
            $translatedSubPageIds = array_merge($translatedSubPageIds, $this->getAllSubpagesForPage(
                (int) $row['l10n_parent'],
                $sysLanguageUids
            ));
        }

        return $translatedSubPageIds;
    }
}
