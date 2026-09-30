..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _introduction-what-it-does:

What does it do?
================

The extension sends TYPO3 records to an Algolia index, so a website can
offer fast and relevant search results. It covers:

*   Pages
*   Content elements
*   News articles (requires EXT:news)
*   Files, including the text content of PDF files

Which records are indexed is configured with records in the TYPO3
backend. A search engine record names the Algolia index, and one or more
indexing service records select the content that goes into it.

..  _introduction-features:

Features
========

*   Configurable indexing services per record type
*   Field mapping via TypoScript, see :ref:`indexers-field-mapping` for
    its limits
*   Backend modules to list and clear the Algolia indexes, to manage the
    indexing queue and to review the attributes sent to Algolia
*   Context menu entry to enqueue a single file directly
*   Per page and per file option to exclude content from search

..  _introduction-requirements:

Requirements
============

*   TYPO3 v14.3
*   PHP 8.3 or later, up to 8.5
*   An Algolia account with an application ID and an API key
*   EXT:news, only if news articles should be indexed

Each major version of the extension supports one TYPO3 version. 1.x
supports TYPO3 v12, 2.x TYPO3 v13 and 3.x TYPO3 v14. All of them
are available in the `TYPO3 Extension Repository
<https://extensions.typo3.org/extension/typo3_search_algolia>`__.
