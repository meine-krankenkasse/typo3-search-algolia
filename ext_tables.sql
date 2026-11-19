#
# Table structure for table 'sys_file_metadata'
#
CREATE TABLE sys_file_metadata
(
    no_search tinyint(3) unsigned DEFAULT '0' NOT NULL
);

#
# Table structure for table 'tx_typo3searchalgolia_domain_model_searchengine'
#
CREATE TABLE tx_typo3searchalgolia_domain_model_searchengine
(
    sorting          int(11) unsigned     DEFAULT '0' NOT NULL,

    title            varchar(100)         DEFAULT ''  NOT NULL,
    description      text,
    engine           varchar(32)          DEFAULT ''  NOT NULL,
    index_name       varchar(255)         DEFAULT ''  NOT NULL
);

#
# Table structure for table 'tx_typo3searchalgolia_domain_model_indexingservice'
#
CREATE TABLE tx_typo3searchalgolia_domain_model_indexingservice
(
    sorting                  int(11) unsigned     DEFAULT '0' NOT NULL,

    title                    varchar(100)         DEFAULT ''  NOT NULL,
    description              text,
    type                     varchar(128)         DEFAULT ''  NOT NULL,
    search_engine            int(10) unsigned     DEFAULT '0',
    include_content_elements smallint(5) unsigned DEFAULT '0' NOT NULL,
    content_element_types    text,
    pages_doktype            text,
    pages_single             text,
    pages_recursive          text,
    file_collections         text
);

#
# Table structure for table 'tx_typo3searchalgolia_domain_model_queueitem'
#
CREATE TABLE tx_typo3searchalgolia_domain_model_queueitem
(
    uid         int(11) unsigned                 NOT NULL auto_increment,
    pid         int(11) unsigned     DEFAULT '0' NOT NULL,
    table_name  varchar(255)         DEFAULT ''  NOT NULL,
    record_uid  int(11)              DEFAULT '0' NOT NULL,
    service_uid int(11)              DEFAULT '0' NOT NULL,
    changed     int(11) unsigned     DEFAULT '0' NOT NULL,
    priority    smallint(5) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY table_name (table_name),
    KEY record_uid (record_uid),
    KEY service_uid (service_uid),
    KEY changed (changed),
    UNIQUE unqiue (table_name, record_uid, service_uid)
);
