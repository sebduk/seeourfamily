-- Migration 004: Merge infos into blog_posts
--
-- blog_posts gains:
--   family_id  - NULL for global (super-admin) posts, set for family-specific posts
--   language   - Language code (ENG, FRA, etc.) for display filtering
--
-- Existing infos are imported as family-specific blog posts.

-- 1. Add new columns to blog_posts
ALTER TABLE blog_posts
    ADD COLUMN family_id INT DEFAULT NULL AFTER uuid,
    ADD COLUMN language  VARCHAR(3) DEFAULT NULL AFTER family_id,
    ADD KEY idx_blog_posts_family (family_id),
    ADD CONSTRAINT fk_blog_posts_family FOREIGN KEY (family_id) REFERENCES families (id);

-- 2. Import infos into blog_posts as published family blog posts.
--    location becomes the title, content becomes the body,
--    family language is used as the blog language.
INSERT INTO blog_posts (uuid, family_id, language, title, body, is_published, published_at, is_online, created_at, updated_by, updated_at)
SELECT
    i.uuid,
    i.family_id,
    f.language,
    COALESCE(i.location, 'Info'),
    COALESCE(i.content, ''),
    1,
    i.created_at,
    i.is_online,
    i.created_at,
    i.updated_by,
    i.updated_at
FROM infos i
JOIN families f ON f.id = i.family_id;
