-- Enable account-backed disclosure drafts for existing installations.
CREATE TABLE IF NOT EXISTS disclosure_draft (
    draft_id      INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT          NOT NULL,
    category_id   INT          DEFAULT NULL,
    title         VARCHAR(120) NOT NULL,
    content       TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_disclosure_draft_admin (admin_user_id),
    CONSTRAINT fk_disclosure_draft_admin FOREIGN KEY (admin_user_id)
        REFERENCES user (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_disclosure_draft_category FOREIGN KEY (category_id)
        REFERENCES disclosure_category (category_id) ON DELETE SET NULL
) ENGINE=InnoDB;
