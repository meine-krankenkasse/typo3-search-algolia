..  include:: /Includes.rst.txt

..  _indexers-news:

============
News indexer
============

The news indexer indexes news records of EXT:news. It is only available
when EXT:news is installed.

To use it, create an indexing service of type :guilabel:`News` and select
the pages that store the news records, see
:ref:`configuration-indexing-service`.

..  _indexers-news-fields:

Fields
======

The news indexer sends the :ref:`standard fields <indexers-standard-fields>`
and the mapped news fields, which default to:

..  code-block:: typoscript
    :caption: Default field mapping of the news indexer

    module.tx_typo3searchalgolia.indexer.tx_news_domain_model_news.fields {
        title = title
        abstract = teaser
        bodytext = description
        author = author
        author_email = authorEmail
        keywords = keywords
    }
