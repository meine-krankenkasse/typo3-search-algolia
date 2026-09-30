..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  _installation-composer:

Composer
========

Install the extension via Composer:

..  code-block:: bash
    :caption: Install the extension

    composer require meine-krankenkasse/typo3-search-algolia

The extension is also available in the `TYPO3 Extension Repository
<https://extensions.typo3.org/extension/typo3_search_algolia>`__.

..  _installation-database:

Update the database structure
=============================

Open :guilabel:`Admin Tools > Maintenance` and run
:guilabel:`Analyze Database Structure` to create the tables and fields
the extension adds.

After installation, continue with :ref:`configuration`.

..  _installation-workspaces:

Workspace support
=================

To queue records for reindexing automatically when they are published
from a workspace, install the workspaces extension:

..  code-block:: bash

    composer require typo3/cms-workspaces

Without it, indexing still works, but publishing from a workspace does
not queue the published records.

..  _installation-uninstall:

Uninstallation
==============

Remove the extension via Composer:

..  code-block:: bash

    composer remove meine-krankenkasse/typo3-search-algolia

Afterwards, consider removing the extension's database tables, the
credentials from your :file:`config/system/additional.php`, and the indexed
data from your Algolia account.
