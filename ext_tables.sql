#
# Table structure for table 'tx_typo3searchalgolia_domain_model_searchengine'
#
CREATE TABLE tx_typo3searchalgolia_domain_model_searchengine
(
    uid              int(11) unsigned                 NOT NULL auto_increment,
    pid              int(11) unsigned     DEFAULT '0' NOT NULL,
    tstamp           int(11) unsigned     DEFAULT '0' NOT NULL,
    crdate           int(11) unsigned     DEFAULT '0' NOT NULL,
    sys_language_uid int(11) unsigned     DEFAULT '0' NOT NULL,
    l10n_parent      int(11) unsigned     DEFAULT '0' NOT NULL,
    l10n_diffsource  mediumblob,
    l10n_source      int(11) unsigned     DEFAULT '0' NOT NULL,
    deleted          smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden           smallint(5) unsigned DEFAULT '0' NOT NULL,
    sorting          int(11) unsigned     DEFAULT '0' NOT NULL,

    title            varchar(100)         DEFAULT ''  NOT NULL,
    description      text,
    engine           varchar(32)          DEFAULT ''  NOT NULL,
    index_name       varchar(255)         DEFAULT ''  NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid)
);

#
# Table structure for table 'tx_typo3searchalgolia_domain_model_indexer'
#
CREATE TABLE tx_typo3searchalgolia_domain_model_indexer
(
    uid              int(11) unsigned                 NOT NULL auto_increment,
    pid              int(11) unsigned     DEFAULT '0' NOT NULL,
    tstamp           int(11) unsigned     DEFAULT '0' NOT NULL,
    crdate           int(11) unsigned     DEFAULT '0' NOT NULL,
    sys_language_uid int(11) unsigned     DEFAULT '0' NOT NULL,
    l10n_parent      int(11) unsigned     DEFAULT '0' NOT NULL,
    l10n_diffsource  mediumblob,
    l10n_source      int(11) unsigned     DEFAULT '0' NOT NULL,
    deleted          smallint(5) unsigned DEFAULT '0' NOT NULL,
    hidden           smallint(5) unsigned DEFAULT '0' NOT NULL,
    sorting          int(11) unsigned     DEFAULT '0' NOT NULL,

    title            varchar(100)         DEFAULT ''  NOT NULL,
    description      text,
    type             varchar(32)          DEFAULT ''  NOT NULL,
    search_engine    int(10) unsigned     DEFAULT '0',

    PRIMARY KEY (uid),
    KEY parent (pid)
);

#
# Table structure for table 'tx_typo3searchalgolia_domain_model_queueitem'
#
CREATE TABLE tx_typo3searchalgolia_domain_model_queueitem
(
    uid          int(11) unsigned                 NOT NULL auto_increment,
    pid          int(11) unsigned     DEFAULT '0' NOT NULL,
    table_name   varchar(255)         DEFAULT ''  NOT NULL,
    record_uid   int(11)              DEFAULT '0' NOT NULL,
    indexer_type varchar(32)          DEFAULT ''  NOT NULL,
    changed      int(11) unsigned     DEFAULT '0' NOT NULL,
    indexed      int(11)              DEFAULT '0' NOT NULL,
    priority     smallint(5) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY record_uid (record_uid),
    KEY table_name (table_name),
    KEY indexer_type (indexer_type),
    KEY changed (changed)
);
