..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The configuration consists of three steps:

#.  Store the Algolia credentials, see :ref:`configuration-credentials`.
#.  Create a search engine record that names the Algolia index, see
    :ref:`configuration-search-engine`.
#.  Create one or more indexing service records that select the content
    to index, see :ref:`configuration-indexing-service`.

Optionally, leave unused content element columns out of the page records,
see :ref:`configuration-exclude-colpos`.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    Credentials
    SearchEngine
    IndexingService
    ExcludeColPos
