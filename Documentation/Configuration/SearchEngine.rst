..  include:: /Includes.rst.txt

..  _configuration-search-engine:

=============
Search engine
=============

First create a folder in the page tree to hold the search configuration
records (search engines, indexing services and, if needed, file
collections). Select this folder in the :guilabel:`List` module.

Create the folder inside the site whose records it indexes. When a page,
content element or news record is saved, only indexing services stored in
the same site as the record queue it. File indexing services are the
exception. They queue a saved file wherever they are stored. Records
outside any site, such as a news storage folder, need their indexing
service outside any site as well.

Then create a new record of type :guilabel:`Search Engine`.

..  figure:: /Images/Configuration-001.png
    :alt: New record wizard with the record type Search Engine
    :class: with-border with-shadow
    :zoom: lightbox

    Create a new record of type :guilabel:`Search Engine`

Enter a title and, optionally, a description. Select the search engine
service to use. The extension ships the :guilabel:`Algolia Search Service`.

..  figure:: /Images/Configuration-002.png
    :alt: Search engine record form with title, description, search engine
        service and index name
    :class: with-border with-shadow
    :zoom: lightbox

    Search engine record

..  confval:: Index name
    :name: search-engine-index-name
    :type: string

    The name of the Algolia index that receives the indexed records.
