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
            'appId' => 'YOUR-APP-ID',
            'apiKey' => 'YOUR-API-KEY',
        ]
    );

..  confval:: appId

    :type: string

    Your Algolia application ID.

..  confval:: apiKey

    :type: string

    An Algolia API key with the permissions needed to write to the
    configured indexes.

Both values are shown in the Algolia dashboard.
