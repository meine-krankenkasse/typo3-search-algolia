..  include:: /Includes.rst.txt

..  _indexers-file:

============
File indexer
============

The file indexer indexes files from the file collections selected in the
indexing service. For PDF files it also extracts the text content.

..  _indexers-file-exclude:

Excluding a file
================

The extension adds an :guilabel:`Include in Search` option to the file
metadata, on a new :guilabel:`Behaviour` tab. It is enabled by default.
Disable it to keep a file out of the index.

..  figure:: /Images/FileIndexer-001.png
    :alt: File metadata, Behaviour tab with the Include in Search toggle
    :class: with-border with-shadow
    :zoom: lightbox

    Exclude a file from indexing

..  _indexers-file-context-menu:

Context menu
============

A single file can be queued directly with the context menu entry
:guilabel:`Add to the Algolia search queue` in the :guilabel:`Filelist`
module.

..  _indexers-file-fields:

Fields
======

In addition to the :ref:`standard fields <indexers-standard-fields>`, the
file indexer sends:

..  list-table::
    :header-rows: 1
    :widths: 20 80

    *   -   Field
        -   Description
    *   -   ``extension``
        -   The file extension.
    *   -   ``mimeType``
        -   The MIME type of the file.
    *   -   ``name``
        -   The file name.
    *   -   ``size``
        -   The file size in bytes.
    *   -   ``url``
        -   The public URL of the file, without the leading slash for
            files in a local storage.
    *   -   ``content``
        -   The extracted text, only for PDF files. Text that repeats
            on the pages of the PDF, such as page headers and footers, is
            removed, and the text is shortened to the size limit described
            in :ref:`indexers-file-size`.

The mapped metadata fields and the allowed file extensions default to:

..  code-block:: typoscript
    :caption: Default configuration of the file indexer

    module.tx_typo3searchalgolia.indexer.sys_file_metadata {
        fields {
            title = title
            description = description
            alternative = alternative
            creator = author
        }

        # Comma-separated list of allowed file extensions
        extensions = pdf
    }

..  _indexers-file-size:

Large files
===========

The extracted text of a PDF file can be larger than the record size limit
of Algolia. The file indexer therefore shortens the text to a configurable
number of bytes. The text is cut at a character boundary, so no multi-byte
character is split. The default is 8000 bytes, which is a conservative value
for the record size limit of the free plan of Algolia.

If your plan allows larger records, raise the limit by overriding the
``$maxContentBytes`` constructor argument of
``MeineKrankenkasse\Typo3SearchAlgolia\EventListener\UpdateAssembledFileDocumentEventListener``
in the :file:`Configuration/Services.yaml` of your own extension:

..  code-block:: yaml
    :caption: EXT:my_site_package/Configuration/Services.yaml

    services:
        _defaults:
            autowire: true

        MeineKrankenkasse\Typo3SearchAlgolia\EventListener\UpdateAssembledFileDocumentEventListener:
            arguments:
                $maxContentBytes: 50000
            tags:
                -   name: event.listener
                    identifier: 'typo3-search-algolia/update-assembled-file-document-event-listener'
                    event: MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent

..  warning::
    Repeat the ``tags`` block exactly as shown. Declaring the service again
    replaces its whole definition. If the tag is missing, the listener is
    no longer registered as an event listener and no error is raised.

Check the record size limit of your Algolia plan before choosing a value.
Leave headroom for the other fields of the record, such as ``name``,
``url``, ``title`` and ``description``, not only for the ``content`` field.

A record that is still too big after the text was shortened, for example
because of other oversized fields, is removed from the queue without being
indexed and a warning is logged.

..  _indexers-file-security:

Sensitive files
===============

Indexed file content becomes searchable. The protection of non-public
storages is not evaluated. Their files are indexed like public ones. Keep
sensitive documents out of the indexed file collections, or exclude them
individually.
