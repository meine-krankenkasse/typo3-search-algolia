..  include:: /Includes.rst.txt

..  _configuration-credentials:

===================
Algolia credentials
===================

Store the Algolia credentials in :file:`config/system/additional.php`,
under the extension key ``typo3_search_algolia``:

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_search_algolia'] = array_merge(
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_search_algolia'] ?? [],
        [
            'appId' => getenv('ALGOLIA_APP_ID') ?: '',
            'apiKey' => getenv('ALGOLIA_API_KEY') ?: '',
        ]
    );

..  confval:: appId

    :type: string

    Your Algolia application ID.

..  confval:: apiKey

    :type: string

    An Algolia API key restricted to the indexes of this installation, with
    the ACLs ``addObject``, ``deleteObject``, ``deleteIndex`` and
    ``listIndexes``. ``deleteIndex`` and ``listIndexes`` are needed for
    clearing and listing indexes in the administration module.

Both values are shown in the Algolia dashboard.

..  warning::
    Do not use the Admin API key, and do not use this key in the frontend.
    Keep it out of version control, for example by reading it from an
    environment variable as shown above.
