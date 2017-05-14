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
 * Called with --sitename:          php update.php --sitename
 *                                  Outputs json encoded information about the sitename from the configuration.php
 *
 * Called with --remove:            php update.php --remove=extension_id (int)
 *                                  Outputs json encoded information about success of
 *                                  removing the extension with given id
 *
 * Called with --core:              php update.php --core
 *                                  Updates the core
 *                                  Outputs json encoded information about success of update
 *
 * Called with --info:              php update.php --info
 *                                  Outputs json encoded information about installed extensions and available updates
 *
 * Called with --extensions:        php update.php --extensions
 *                                  Updates all extensions
 *                                  Outputs json encoded information about success of each updated extension
 *
 * Called with --extension:         php update.php --extension=extension_id (int)
 *                                  Outputs json encoded information about the extension with the given id
 *      option --update:            Updates the extension and add information about success to the output
 *      option --enable:            Enables the extension and add information about status to the output
 *      option --disable:           Disables the extension and add information about status to the output
 *
 * Called with --installpackage:    php update.php --installpackage=package.zip (path)
 *                                  Installs archived extension package that has to be placed in the tmp folder
 *                                  Outputs json encoded information about success of installation from extension
 *      option --enable:            Enables the extension and add information about status to the output
 *      option --disable:           Not required because default value
 *
 *
 * Called with --installurl:        php update.php --installurl=url/package.zip (url)
 *                                  Installs archived extension package from a URL
 *                                  Outputs json encoded information about success of installation from extension
 *      option --enable:            Enables the extension and add information about status to the output
 *      option --disable:           Not required because default value
 */

if (php_sapi_name() != 'cli')
{
	exit(1);
}

// We are a valid entry point.
const _JEXEC = 1;

// Define core extension id
const CORE_EXTENSION_ID = 700;

error_reporting(E_ALL);
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

require_once JPATH_LIBRARIES . '/import.legacy.php';
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
 * @since  3.7
 */
class JoomlaCliUpdate extends JApplicationCli
{
	/**
	 * The Installer Model
	 *
	 * @var    InstallerModelUpdate
	 * @since  3.7
	 */
	protected $updater = null;

	/**
	 * Joomla! Site Application
	 *
	 * @var    JApplicationSite
	 * @since  3.7
	 */
	protected $app = null;

	/**
	 * Entry point for the script
	 *
	 * @return  void
	 * @since   3.7
	 */
	public function doExecute()
	{
		$_SERVER['HTTP_HOST'] = 'localhost';
		$this->app            = JFactory::getApplication('site');

		if ($this->input->get('sitename', false))
		{
			$this->out(json_encode(array('sitename' => $this->getSiteInfo())));

			return;
		}

		$remove = $this->input->get('remove', false, 'INTEGER');

		if (!empty($remove))
		{
			$this->out(json_encode(array('success' => $this->removeExtension($remove))));

			return;
		}

		if ($this->input->get('core', false))
		{
			$this->out(json_encode(array('success' => $this->updateCore())));

			return;
		}

		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_installer/models');
		$this->updater = JModelLegacy::getInstance('Update', 'InstallerModel');

		if ($this->input->get('info', false))
		{
			$this->out(json_encode($this->infoInstalledExtensions()));

			return;
		}

		if ($this->input->get('extensions', false))
		{
			$this->out(json_encode($this->updateExtensions()));

			return;
		}

		$extension_id = $this->input->get('extension', false, 'INTEGER');
		$enable       = $this->input->get('enable', false, 'BOOLEAN');
		$disable      = $this->input->get('disable', false, 'BOOLEAN');
		$update       = $this->input->get('update', false, 'BOOLEAN');

		if (!empty($extension_id))
		{
			$output = array(
				'update' => false,
				'enable' => false,
				'disable' => false
			);

			if (!$enable && !$disable)
			{
				$update = true;
			}

			if ($update)
			{
				$output['update'] = array('success' => $this->updateExtension($extension_id));
			}

			if ($enable)
			{
				$output['enable'] = array('success' => $this->setExtensionEnabled($extension_id));
			}

			if ($disable)
			{
				$output['disable'] = array('success' => $this->setExtensionState($extension_id, 0));
			}

			$this->out(json_encode($this->updateExtension($extension_id)));

			return;

			$this->out(json_encode($this->updateExtension($extension_id)));

			return;
		}

		$installPackage = $this->input->get('installpackage', '', 'STRING');
		$installUrl     = $this->input->get('installurl', '', 'STRING');

		if (!empty($installPackage) || !empty($installUrl))
		{
			$output = array(
				'success' => false
			);

			if (!empty($installPackage))
			{
				$extName = $this->installExtension($installPackage, 'folder');
			}

			if (!empty($installUrl))
			{
				$extName = $this->installExtension($installUrl, 'url');
			}

			if (false !== $extName)
			{
				$output['success'] = true;
			}

			if (false !== $enable)
			{
				$output['enabled'] = $this->setExtensionEnabled($this->getExtensionId($extName));
			}

			$this->out(json_encode($output));

			return;
		}
	}

	/**
	 * Get the sitename out of the Joomla config
	 *
	 * @return  string
	 * @since   3.7
	 */
	private function getSiteInfo()
	{
		return JFactory::getApplication()->get('sitename');
	}

	/**
	 * Remove an extension
	 *
	 * @param   int $extension_id
	 *
	 * @return  boolean
	 * @since   3.7
	 */
	protected function removeExtension($extension_id)
	{
		$id = (int) $extension_id;

		$result = false;

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
	 * Update Core Joomla
	 *
	 * @return  boolean
	 * @since   3.7
	 */
	public function updateCore()
	{
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_joomlaupdate/models');
		$jUpdate = JModelLegacy::getInstance('Default', 'JoomlaupdateModel');

		$jUpdate->purge();

		$jUpdate->refreshUpdates(true);

		$updateInformation = $jUpdate->getUpdateInformation();

		if (!empty($updateInformation['hasUpdate']))
		{
			$packagefile = JInstallerHelper::downloadPackage($updateInformation['object']->downloadurl->_data);
			$tmp_path    = $this->app->get('tmp_path');
			$packagefile = $tmp_path . '/' . $packagefile;
			$package     = JInstallerHelper::unpack($packagefile, true);
			JFolder::copy($package['extractdir'], JPATH_BASE, '', true);

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
	 * Gets the Information about all installed extensions from the database.
	 *
	 * @return  array
	 * @since   3.7
	 */
	public function infoInstalledExtensions()
	{
		$lang = JFactory::getLanguage();

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

		$updates = $this->getUpdates();

		$toUpdate = array();
		$upToDate = array();

		foreach ($extensions as &$extension)
		{
			$extension['name'] = JText::_($extension['name']);

			$tmp                   = $extension;
			$tmp['currentVersion'] = json_decode($tmp['manifest_cache'], true)['version'];
			$tmp['enabled']        = (boolean) $extension['enabled'];

			if (array_key_exists($extension['extension_id'], $updates))
			{
				$tmp['newVersion']  = $updates[$tmp['extension_id']]['version'];
				$tmp['needsUpdate'] = true;

				$toUpdate[] = $tmp;
			}
			else
			{
				$tmp['newVersion']  = $tmp['currentVersion'];
				$tmp['needsUpdate'] = false;

				$upToDate[] = $tmp;
			}
		}

		return array_merge($toUpdate, $upToDate);
	}

	/**
	 * Get the list of available language sys files
	 *
	 * @return  array  List of languages
	 * @since   3.7
	 */
	private function getLanguageFiles()
	{
		return JFolder::files(JPATH_ADMINISTRATOR . '/language/en-GB/', '\.sys\.ini');
	}

	/**
	 * Get all extensions
	 *
	 * @return  array  AssocList with all extension from #__extensions
	 * @since   3.7
	 */
	private function getAllExtensions()
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->select('*')
			->from('#__extensions');

		$db->setQuery($query);

		return $db->loadAssocList('extension_id');
	}

	/**
	 * Find updates
	 *
	 * @param  int  $eid  The extension id
	 *
	 * @return  void
	 * @since   3.7
	 */
	private function findUpdates($eid = 0)
	{
		$updater = JUpdater::getInstance();

		// Fills potential updates into the table '#__updates for ALL extensions
		$updater->findUpdates($eid);
	}

	/**
	 * Get available updates from #__updates
	 *
	 * @return  array  AssocList with extension ids of available updates
	 * @since   3.7
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
	 * Update Extensions
	 *
	 * @return  array  Array with success information for each extension
	 * @since   3.7
	 */
	public function updateExtensions()
	{
		$this->updater->purge();

		$this->findUpdates();

		// Get the objects
		$extensions = $this->getUpdateIds();

		$result = array();

		foreach ($extensions as $e)
		{
			// Check if ist core or extension
			if ($e->extension_id == CORE_EXTENSION_ID)
			{
				$result[$e->extension_id] = $this->updateCore();
			}
			else
			{
				$this->updater->update([$e->update_id]);
				$result[$e->extension_id] = $this->updater->getState('result');
			}
		}

		return $result;
	}

	/**
	 * Get the update
	 *
	 * @param   int|null  $eid  The extenion id or null for all
	 *
	 * @return  object|array
	 * @since   3.7
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
	 * Update a single extension
	 *
	 * @param   int  $eid  The extension_id
	 *
	 * @return  boolean  True on success
	 * @since   3.7
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
	 * Installs an extension (From tmp_path or URL)
	 *
	 * @param   string  $filename  Filename or URL from installpackage
	 * @param   string  $method    Set to 'url' if not local
	 *
	 * @return  boolean|string  extension name or false on error
	 * @since   3.7
	 */
	public function installExtension($filename, $method = null)
	{
		$return = false;

		if ($method == 'url')
		{
			$filename = JInstallerHelper::downloadPackage($filename);
		}

		$tmp_path = $this->app->get('tmp_path');
		$path     = $tmp_path . '/' . basename($filename);
		$package  = JInstallerHelper::unpack($path, true);

		if ($package['type'] === false)
		{
			return $return;
		}

		$jInstaller = JInstaller::getInstance();
		$result     = $jInstaller->install($package['extractdir']);
		JInstallerHelper::cleanupInstall($path, $package['extractdir']);

		if ($result)
		{
			$return = (string) $jInstaller->getManifest()->name;
		}

		return $return;
	}

	/**
	 * Set extension enabled state
	 *
	 * @param   int  $extension_id
	 * @param   int  $state
	 *
	 * @return  boolean
	 * @since   3.7
	 */
	private function setExtensionEnabled($extension_id, $state = 1)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled') . ' = ' . (int) $state)
			->where($db->quoteName('extension_id') . '=' . (int) $extension_id);

		$db->setQuery($query);

		return $db->execute();
	}

	/**
	 * Get extension id by name from #__extensions
	 *
	 * @param   string  $extension_name
	 *
	 * @return  int
	 * @since   3.7
	 */
	private function getExtensionId($extension_name)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('name') . '=' . $db->quote($extension_name));

		$db->setQuery($query);

		return $db->loadResult();
	}
}

JApplicationCli::getInstance('JoomlaCliUpdate')->execute();
