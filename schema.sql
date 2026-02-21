-- =============================================
-- schema.sql - 데이터베이스 스키마
-- mysql -u root -p bid_monitor < schema.sql
-- =============================================

CREATE DATABASE IF NOT EXISTS bid_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bid_monitor;

-- 키워드 테이블
CREATE TABLE IF NOT EXISTS keywords (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    keyword    VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 기본 키워드 삽입
INSERT IGNORE INTO keywords (keyword) VALUES
    ('AI'), ('인공지능'), ('소프트웨어 개발'), ('데이터 플랫폼'), ('클라우드'), ('스마트팩토리');

-- 입찰 공고 테이블
CREATE TABLE IF NOT EXISTS bids (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(500) NOT NULL,
    url           TEXT NOT NULL,
    source        VARCHAR(50) NOT NULL COMMENT '나라장터, K-스타트업, IITP, 중소기업기술정보진흥원',
    org_name      VARCHAR(200),
    budget        VARCHAR(100),
    budget_raw    BIGINT DEFAULT 0,
    deadline_date DATE,
    fetched_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_title_source (title(200), source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 공고-키워드 연결 테이블
CREATE TABLE IF NOT EXISTS bid_keywords (
    bid_id     INT NOT NULL,
    keyword_id INT NOT NULL,
    PRIMARY KEY (bid_id, keyword_id),
    FOREIGN KEY (bid_id)     REFERENCES bids(id)     ON DELETE CASCADE,
    FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 인덱스
CREATE INDEX idx_bids_fetched   ON bids (fetched_at);
CREATE INDEX idx_bids_deadline  ON bids (deadline_date);
CREATE INDEX idx_bids_source    ON bids (source);
