# CLI-Update

Proof of concept on how to update/manage Joomla from the command line 

Parameter:

php cli/update.php 

--core

--extension[=ID_OF_THE_EXTENSION]

--info

--sitename

--installpackage=[ARCHIVE_FILE_IN_TMP]

--installurl=[ARCHIVE_FILE_URL]

--remove=ID_OF_THE_EXTENSION

# Description

--core: 

Updates the Joomla! Core CMS

--extension[=ID_OF_THE_EXTENSION]:

Updates all or a single Extension

--info

Gives Information about all installed Extensions as a json

--sitename

Returns the sitename

--installpackage=[ARCHIVE_FILE_IN_TMP]

Installs an extension, provide the name of the the package file which is placed in the tmp folder

--installurl=[URL]

Installs an extension, provide the URL to the archive package

--remove=ID_OF_THE_EXTENSION

Removes an extension, provide the extension id
