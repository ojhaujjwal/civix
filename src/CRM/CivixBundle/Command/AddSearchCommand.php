<?php
namespace CRM\CivixBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use CRM\CivixBundle\Builder\Collection;
use CRM\CivixBundle\Builder\CopyClass;
use CRM\CivixBundle\Builder\CopyFile;
use CRM\CivixBundle\Builder\Dirs;
use CRM\CivixBundle\Builder\Info;
use CRM\CivixBundle\Builder\Module;
use CRM\CivixBundle\Builder\PhpData;
use CRM\CivixBundle\Builder\Template;
use CRM\CivixBundle\Utils\Path;

class AddSearchCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('generate:search')
            ->setDescription('Add a custom search to a module-extension')
            ->addArgument('className', InputArgument::REQUIRED, 'Search class name (eg "MySearch")')
            ->addOption('copy', null, InputOption::VALUE_OPTIONAL, 'Full class name of an existing search which should be copied (eg "CRM_Contact_Search_Custom_ZipCodeRange")')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //// Figure out template data ////
        $ctx = array();
        $ctx['type'] = 'module';
        $ctx['basedir'] = rtrim(getcwd(),'/');
        $basedir = new Path($ctx['basedir']);

        $info = new Info($basedir->string('info.xml'));
        $info->load($ctx);
        $attrs = $info->get()->attributes();
        if ($attrs['type'] != 'module') {
            $output->writeln('<error>Wrong extension type: '. $attrs['type'] . '</errror>');
            return;
        }

        $ctx['searchClassName'] = strtr($ctx['namespace'], '/', '_') . '_Search_Custom_' . $input->getArgument('className');
        $ctx['searchClassFile'] = $basedir->string(strtr($ctx['searchClassName'], '_', '/') . '.php');
        $ctx['searchMgdFile'] = $basedir->string(strtr($ctx['searchClassName'], '_', '/') . '.mgd.php');
//tpl        $ctx['searchTplFile'] = $basedir->string('templates', strtr($ctx['searchClassName'], '_', '/') . '.tpl');

        //// Construct files ////
        $output->writeln("<info>Initialize search ".$ctx['searchClassName']."</info>");

        $ext = new Collection();
        $ext->builders['dirs'] = new Dirs(array(
            dirname($ctx['searchClassFile']),
            dirname($ctx['searchMgdFile']),
//tpl            dirname($ctx['searchTplFile']),
        ));

        if (!file_exists($ctx['searchMgdFile'])) {
            $mgdEntities = array(
              array(
                'name' => $ctx['searchClassName'],
                'entity' => 'CustomSearch',
                'params' => array(
                  'version' => 3,
                  'label' => $input->getArgument('className'),
                  'description' => sprintf("%s (%s)", $input->getArgument('className'), $ctx['fullName']),
                  'class_name' => $ctx['searchClassName'],
                ),
              ),
            );
            $header = "// This file declares a managed database record of type \"CustomSearch\".\n"
                . "// The record will be automatically inserted, updated, or deleted from the\n"
                . "// database as appropriate. For more details, see \"hook_civicrm_managed\" at:\n"
                . "// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference";
            $ext->builders['mgd.php'] = new PhpData($ctx['searchMgdFile'], $header);
            $ext->builders['mgd.php']->set($mgdEntities);
        }

        if ($srcClassName = $input->getOption('copy')) {
            // we need bootstrap to set up include path to locate file -- but that's it
            $civicrm_api3 = $this->getContainer()->get('civicrm_api3');
            if (!$civicrm_api3 || !$civicrm_api3->local) {
              $output->writeln("<error>--copy requires access to local CiviCRM source tree. Configure civicrm_api3_conf_path.</error>");
              return;
            }

//tpl            $origTplFile = 'templates/' . preg_replace('/_/','/', $srcClassName) . '.tpl';
            $ext->builders['search.php'] = new CopyClass($srcClassName, $ctx['searchClassName'], $ctx['searchClassFile'], FALSE);
//tpl            $ext->builders['page.tpl.php'] = new CopyFile($origTplFile, $ctx['searchTplFile'], FALSE);
        } else {
            $ext->builders['search.php'] = new Template('CRMCivixBundle:Code:search.php.php', $ctx['searchClassFile'], FALSE, $this->getContainer()->get('templating'));
//tpl            $ext->builders['page.tpl.php'] = new Template('CRMCivixBundle:Code:search.tpl.php', $ctx['searchTplFile'], FALSE, $this->getContainer()->get('templating'));
        }

        $ext->init($ctx);
        $ext->save($ctx, $output);
    }

}