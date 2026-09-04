-- posts.li_post_urn is LinkedIn-specific by name and convention; the
-- new non-LinkedIn platforms (Facebook/Instagram/Pinterest/Google
-- Business Profile) each return their own created-post/pin/localPost
-- id, which needs somewhere generic to live rather than overloading a
-- column named for LinkedIn's URN format. See includes/social_publish.php.
ALTER TABLE posts ADD COLUMN external_post_id VARCHAR(255) NULL AFTER li_post_urn;
