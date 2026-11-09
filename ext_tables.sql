CREATE TABLE sys_redirect (
    tx_redirectlifecycle_mode smallint unsigned DEFAULT 0 NOT NULL,
    tx_redirectlifecycle_delete_after bigint unsigned DEFAULT 0 NOT NULL,
    tx_redirectlifecycle_disabled_retention smallint unsigned DEFAULT 0 NOT NULL,
    KEY redirect_lifecycle_cleanup (tx_redirectlifecycle_mode,deleted,disabled,protected,tx_redirectlifecycle_delete_after),
    KEY redirect_lifecycle_expiry (tx_redirectlifecycle_mode,deleted,disabled,protected,endtime),
    KEY redirect_lifecycle_disabled (disabled,protected,deleted,tx_redirectlifecycle_disabled_retention,tx_redirectlifecycle_delete_after)
);
