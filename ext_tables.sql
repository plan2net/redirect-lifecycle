CREATE TABLE sys_redirect (
    tx_redirectlifecycle_mode smallint unsigned DEFAULT 0 NOT NULL,
    tx_redirectlifecycle_delete_after bigint unsigned DEFAULT 0 NOT NULL,
    KEY redirect_lifecycle_cleanup (tx_redirectlifecycle_mode,deleted,disabled,protected,tx_redirectlifecycle_delete_after)
);
