CREATE TABLE tx_aisteaseo_domain_model_report (
    title varchar(255) NOT NULL DEFAULT '',
    base_url varchar(500) NOT NULL DEFAULT '',
    status smallint(6) unsigned NOT NULL DEFAULT 0,
    pages_crawled int(11) unsigned NOT NULL DEFAULT 0,
    progress_pages int(11) unsigned NOT NULL DEFAULT 0,
    max_pages int(11) unsigned NOT NULL DEFAULT 50,
    overall_score smallint(6) unsigned NOT NULL DEFAULT 0,
    error_message text,
    queued_at int(11) unsigned NOT NULL DEFAULT 0,
    started_at int(11) unsigned NOT NULL DEFAULT 0,
    finished_at int(11) unsigned NOT NULL DEFAULT 0,
    last_crawled_url varchar(1000) NOT NULL DEFAULT ''
);

CREATE TABLE tx_aisteaseo_domain_model_page (
    report int(11) unsigned NOT NULL DEFAULT 0,
    url varchar(1000) NOT NULL DEFAULT '',
    status_code smallint(6) unsigned NOT NULL DEFAULT 0,
    page_title varchar(500) NOT NULL DEFAULT '',
    title_length smallint(6) unsigned NOT NULL DEFAULT 0,
    meta_description text,
    meta_description_length smallint(6) unsigned NOT NULL DEFAULT 0,
    h1_count smallint(6) unsigned NOT NULL DEFAULT 0,
    h1_text varchar(500) NOT NULL DEFAULT '',
    h2_count smallint(6) unsigned NOT NULL DEFAULT 0,
    canonical_url varchar(1000) NOT NULL DEFAULT '',
    robots_noindex tinyint(1) unsigned NOT NULL DEFAULT 0,
    robots_nofollow tinyint(1) unsigned NOT NULL DEFAULT 0,
    images_total smallint(6) unsigned NOT NULL DEFAULT 0,
    images_missing_alt smallint(6) unsigned NOT NULL DEFAULT 0,
    links_internal smallint(6) unsigned NOT NULL DEFAULT 0,
    links_external smallint(6) unsigned NOT NULL DEFAULT 0,
    word_count int(11) unsigned NOT NULL DEFAULT 0,
    load_time int(11) unsigned NOT NULL DEFAULT 0,
    page_score smallint(6) unsigned NOT NULL DEFAULT 0,
    issues text
);
