<?php
/**
 * @package    Joomla.Cli
 *
 * @copyright  Copyright (C) 2005 - 2016 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
/**
 * Manage and Update Joomla installation and extensions
 *
 * Called with --core:              php update.php --core
 *                                  Updates the core
 *
 * Called with --extensions:        php update.php --extensions
 *                                  Updates all extensions
 *
 * Called with --extension:         php update.php --extension=extension_id (int)
 *                                  Updates the extension with the given id
 *
 * Called with --sitename:          php update.php --sitename
 *                                  Outputs the sitename from the configuration.php as json object
 *
 * Called with --info:              php update.php --info
 *                                  Outputs json encoded informations about installed extensions and
 *                                  available extensions
 *
 * Called with --installpackage:    php update.php --installpackage=package.zip (path)
 *                                  Installs archived extension package that has to be placed in the tmp folder
 *
 *
 * Called with --installurl:        php update.php --installurl=url/package.zip (url)
 *                                  Installs archived extension package from a URL
 *
 * Called with --remove:            php update.php --remove=extension_id (int)
 *                                  Removes the extension with the given id
 */
if (php_sapi_name() != 'cli')
{
	exit(1);
}
// We are a valid entry point.
const _JEXEC = 1;
// Define core extension id
const CORE_EXTENSION_ID = 700;
const DS = DIRECTORY_SEPARATOR;
//error_reporting(E_ALL);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 1);
// Load system defines
if (file_exists(dirname(__DIR__) . '/defines.php'))
{
	require_once dirname(__DIR__) . '/defines.php';
}
if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', dirname(__DIR__));
	require_once JPATH_BASE . '/includes/defines.php';
}
if (file_exists(JPATH_LIBRARIES . '/import.legacy.php'))
{
	//Load on J3x
	require_once JPATH_LIBRARIES . '/import.legacy.php';
}
else
{
	// Load on J25
	require_once JPATH_LIBRARIES . '/import.php';
}
require_once JPATH_LIBRARIES . '/cms.php';
// Load the configuration
require_once JPATH_CONFIGURATION . '/configuration.php';
// Load the JApplicationCli class
JLoader::import('joomla.application.cli');
JLoader::import('joomla.application.component.helper');
JLoader::import('joomla.filesystem.folder');
JLoader::import('joomla.filesystem.file');

/**
 * Manage Joomla extensions and core on the commandline
 *
 * @since  3.5.1
 */
class JoomlaCliUpdate extends JApplicationCli
{
	/**
	 * The Installer Model
	 *
	 * @var    InstallerModelUpdate
	 */
	protected $updater = null;
	/**
	 * Joomla! Site Application
	 *
	 * @var    JApplicationSite
	 */
	protected $app = null;

	/**
	 * Entry point for the script
	 *
	 * @return  void
	 */
	public function doExecute()
	{
		$_SERVER['HTTP_HOST'] = 'localhost';
		$this->app            = JFactory::getApplication('site');
		if ($this->input->get('sitename', false))
		{
			$this->out($this->getSiteInfo());

			return;
		}
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_installer/models');
		$this->updater = JModelLegacy::getInstance('Update', 'InstallerModel');
		if ($this->input->get('core', false))
		{
			$this->out(json_encode(array('700' => $this->updateCore())));

			return;
		}
		if ($this->input->get('info', false))
		{
			$this->out(json_encode($this->infoInstalledVersions()));

			return;
		}
		if ($this->input->get('extensions', false))
		{
			$this->out(json_encode($this->updateExtensions()));

			return;
		}
		$extension_id = $this->input->get('extension', false, 'INTEGER');
		if (!empty($extension_id))
		{
			$this->out(json_encode($this->updateExtension($extension_id)));

			return;
		}
		$installPackage = $this->input->get('installpackage', '', 'PATH');
		if (!empty($installPackage))
		{
			$this->out(json_encode(array('result' => $this->installExtension($installPackage, 'folder'))));

			return;
		}
		$installUrl = $this->input->get('installurl', '', 'STRING');
		if (!empty($installUrl))
		{
			$this->out(json_encode(array('result' => $this->installExtension($installUrl, 'url'))));

			return;
		}
		$remove = $this->input->get('remove', '');
		if ($remove != '')
		{
			return $this->removeExtension($remove);
		}
	}

	/**
	 * Remove an extension
	 *
	 * @param   int $param Extension id
	 *
	 * @return  bool
	 */
	protected function removeExtension($param)
	{
		$id        = (int) $param;
		$result    = true;
		$installer = JInstaller::getInstance();
		$row       = JTable::getInstance('extension');
		$row->load($id);
		if ($row->type && $row->type != 'language')
		{
			$result = $installer->uninstall($row->type, $id);
		}

		return $result;
	}

	/**
	 * Installs an extension (From directory or URL)
	 *
	 * @param   string $path
	 * @param   string $method
	 *
	 * @return  bool
	 */
	public function installExtension($path, $method)
	{
		if ($method == 'url')
		{
			$path = JInstallerHelper::downloadPackage($path);
		}
		$path    = JPATH_BASE . '/tmp/' . basename($path);
		$package = JInstallerHelper::unpack($path, true);
		if ($package['type'] === false)
		{
			return false;
		}
		$jInstaller = JInstaller::getInstance();
		$result     = $jInstaller->install($package['extractdir']);
		JInstallerHelper::cleanupInstall($path, $package['extractdir']);

		return $result;
	}

	/**
	 * Gets the Information about all installed extensions from the database and checked the database.
	 * Outputs to the cli as json string
	 *
	 * @return  void
	 */
	public function infoInstalledVersions()
	{
		$lang      = JFactory::getLanguage();
		$langFiles = $this->getLanguageFiles();
		foreach ($langFiles as $file)
		{
			$file = str_replace(array('en-GB.', '.ini'), '', $file);
			$lang->load($file, JPATH_ADMINISTRATOR, 'en-GB', true, false);
		}
		// Get All extensions
		$extensions = $this->getAllExtensions();
		$this->updater->purge();
		$this->findUpdates(0);
		$updates  = $this->getUpdates();
		$toUpdate = array();
		$upToDate = array();
		foreach ($extensions as &$extension)
		{
			$extension['name'] = JText::_($extension['name']);
			if (array_key_exists($extension['extension_id'], $updates))
			{
				$tmp                   = $extension;
				$tmp['currentVersion'] = json_decode($tmp['manifest_cache'], true)['version'];
				$tmp['newVersion']     = $updates[$tmp['extension_id']]['version'];
				$tmp['needsUpdate']    = true;
				$toUpdate[]            = $tmp;
			}
			else
			{
				$tmp                   = $extension;
				$tmp['currentVersion'] = json_decode($tmp['manifest_cache'], true)['version'];
				$tmp['newVersion']     = $tmp['currentVersion'];
				$tmp['needsUpdate']    = false;
				$upToDate[]            = $tmp;
			}
		}

		return array_merge($toUpdate, $upToDate);
	}

	/**
	 * Get the list of available language sys files
	 *
	 * @return  array  List of languages
	 */
	private function getLanguageFiles()
	{
		return JFolder::files(JPATH_ADMINISTRATOR . '/language/en-GB/', '\.sys\.ini');
	}

	/**
	 * Update Core Joomla
	 *
	 * @return  bool  success
	 */
	public function updateCore()
	{
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_joomlaupdate/models');
		$jUpdate = JModelLegacy::getInstance('Default', 'JoomlaupdateModel');

		if (version_compare(JVERSION, "3", 'gt'))
		{
			$jUpdate->purge();
		}

		$jUpdate->refreshUpdates(true);
		$updateInformation = $jUpdate->getUpdateInformation();
		if (!empty($updateInformation['object']))
		{
			$packagefile = JInstallerHelper::downloadPackage($updateInformation['object']->downloadurl->_data);
			$packagefile = JPATH_BASE . '/tmp/' . basename($packagefile);
			$package     = JInstallerHelper::unpack($packagefile, true);
			JFolder::copy($package['extractdir'], JPATH_BASE, '', true, true);
			$result = $jUpdate->finaliseUpgrade();
			if ($result)
			{
				// Remove the xml
				if (file_exists(JPATH_BASE . '/joomla.xml'))
				{
					JFile::delete(JPATH_BASE . '/joomla.xml');
				}
				JInstallerHelper::cleanupInstall($packagefile, $package['extractdir']);

				return true;
			}
		}

		return false;
	}

	/**
	 * Update a single extension
	 *
	 * @param   int $eid - The extension_id
	 *
	 * @return  bool  success
	 */
	public function updateExtension($eid)
	{
		$this->updater->purge();
		$this->findUpdates($eid);
		$update_id = $this->getUpdateIds($eid);
		$this->updater->update($update_id);
		$result = $this->updater->getState('result');

		return $result;
	}

	/**
	 * Update Extensions
	 *
	 * @return  array  Array with success information for each extension
	 */
	public function updateExtensions()
	{
		$this->updater->purge();
		$this->findUpdates();
		// Get the objects
		$extensions = $this->getUpdateIds();
		$result     = array();
		foreach ($extensions as $e)
		{
			$this->updater->update([$e->update_id]);
			$result[$e->extension_id] = $this->updater->getState('result');
		}

		return $result;
	}

	/**
	 * Find updates
	 *
	 * @param  int $eid The extension id
	 *
	 * @return  void
	 */
	private function findUpdates($eid = 0)
	{
		$updater = JUpdater::getInstance();
		// Fills potential updates into the table '#__updates for ALL extensions
		$updater->findUpdates($eid);
	}

	/**
	 * Get the update
	 *
	 * @param   int|null $eid The extenion id or null for all
	 *
	 * @return  object|array
	 */
	private function getUpdateIds($eid = null)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('update_id, extension_id')
			->from('#__updates')
			->where($db->qn('extension_id') . ' <> 0');
		if (!is_null($eid))
		{
			$query->where($db->qn('extension_id') . ' = ' . $db->q($eid));
		}
		$db->setQuery($query);
		$result = $db->loadObjectList();
		if (!$result)
		{
			return array();
		}
		if ($eid)
		{
			// Return only update id
			return (array) $result[0]->update_id;
		}

		return $result;
	}

	/**
	 * Get available updates from #__updates
	 *
	 * @return  array  AssocList with available updates
	 */
	private function getUpdates()
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from('#__updates')
			->where($db->qn('extension_id') . ' <> 0');
		$db->setQuery($query);

		return $db->loadAssocList('extension_id');
	}

	/**
	 * Get all extensions
	 *
	 * @return  array  AssocList with all extensions from #__extensions
	 */
	private function getAllExtensions()
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from('#__extensions')
			->where('extension_id > 10000')
			->where('type != "template"');
		$db->setQuery($query);

		return $db->loadAssocList('extension_id');
	}

	/**
	 * Get the sitename json encoded out of the Joomla config
	 *
	 * @return  string  The json encoded result
	 */
	public function getSiteInfo()
	{
		$info           = new stdClass();
		$info->sitename = JFactory::getApplication()->getCfg('sitename');

		return json_encode($info);
	}
}

JApplicationCli::getInstance('JoomlaCliUpdate')->execute();