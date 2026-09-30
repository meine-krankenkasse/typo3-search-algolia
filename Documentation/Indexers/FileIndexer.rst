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

A single file can be queued directly from the context menu in the
:guilabel:`Media` module.

..  figure:: /Images/FileIndexer-002.png
    :alt: Media module context menu with the entry to queue a file for indexing
    :class: with-border with-shadow
    :zoom: lightbox

    Queue a single file for indexing

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
        -   The public URL of the file, relative for files in a local
            storage.
    *   -   ``content``
        -   The extracted text, only for PDF files.

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

Indexed file content becomes searchable. Keep sensitive documents out of
the indexed file collections, or exclude them individually.
