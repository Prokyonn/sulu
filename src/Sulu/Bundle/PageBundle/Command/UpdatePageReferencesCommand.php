<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PageBundle\Command;

use Jackalope\Query\Row;
use Jackalope\Query\RowIterator;
use PHPCR\SessionInterface;
use Sulu\Bundle\PageBundle\Document\BasePageDocument;
use Sulu\Bundle\PageBundle\Reference\PageReferenceProvider;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpdatePageReferencesCommand extends Command
{
    // TODO should this be unter the sulu:content namespace?
    protected static $defaultName = 'sulu:pages:update-references';

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var WebspaceManagerInterface
     */
    private $webspaceManager;

    /**
     * @var DocumentManagerInterface
     */
    private $documentManager;

    /**
     * @var PageReferenceProvider
     */
    private $pageReferenceProvider;

    public function __construct(
        SessionInterface $session,
        WebspaceManagerInterface $webspaceManager,
        DocumentManagerInterface $documentManager,
        PageReferenceProvider $pageReferenceProvider
    ) {
        parent::__construct();

        $this->session = $session;
        $this->webspaceManager = $webspaceManager;
        $this->documentManager = $documentManager;
        $this->pageReferenceProvider = $pageReferenceProvider;
    }

    protected function configure(): void
    {
        $this->addArgument('webspaceKey', InputArgument::REQUIRED, 'Which webspace to search')
            ->setDescription('Updates all references for pages in the given webspace');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $webspaceKey = $input->getArgument('webspaceKey');

        /** @var Webspace $webspace */
        $webspace = $this->webspaceManager->findWebspaceByKey($webspaceKey);

        $sql2 = \sprintf(
            "SELECT jcr:uuid FROM [nt:unstructured] as page WHERE page.[jcr:mixinTypes] = 'sulu:page' AND (isdescendantnode(page, '/cmf/%s/contents') OR issamenode(page, '/cmf/%s/contents'))",
            $webspaceKey,
            $webspaceKey
        );

        $queryManager = $this->session->getWorkspace()->getQueryManager();
        $query = $queryManager->createQuery($sql2, 'JCR-SQL2');
        $queryResult = $query->execute();

        $ui = new SymfonyStyle($input, $output);
        $ui->info('Updating references for pages in webspace "' . $webspaceKey . '"');
        /** @var RowIterator $rows */
        $rows = $queryResult->getRows();
        $ui->progressStart(\count($webspace->getAllLocalizations()) * $rows->count());
        foreach ($webspace->getAllLocalizations() as $localization) {
            $locale = $localization->getLocale();
            /** @var Row $row */
            foreach ($rows as $row) {
                /** @var string $uuid */
                $uuid = $row->getValue('jcr:uuid');
                /** @var BasePageDocument|null $document */
                $document = $this->documentManager->find($uuid, $locale);

                if (!$document) {
                    continue;
                }

                $this->pageReferenceProvider->updateReferences($document, $locale);
                $ui->progressAdvance();
            }
        }

        $ui->success('Finished');

        return Command::SUCCESS;
    }
}
