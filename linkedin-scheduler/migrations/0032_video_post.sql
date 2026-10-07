-- LinkedIn Video Post support (LinkedIn only — other platforms don't get
-- this yet). video_filename/video_filepath are dedicated columns rather
-- than a post_slides row: post_slides' slide_order/uniq_post_order and
-- every caller's "0+ ordered image slides" assumptions (carousel-PDF
-- building, MAX_SLIDES_PER_CAMPAIGN, count($slidePaths)===1 meaning
-- Single Image) all assume images, not a single video file. See
-- includes/linkedin_api.php li_upload_video(), includes/mcp_tools.php.
ALTER TABLE posts MODIFY COLUMN format ENUM('Single Image','Carousel','Text Post','Poll','Video Post') NOT NULL;
ALTER TABLE posts
  ADD COLUMN video_filename VARCHAR(255) NULL AFTER li_post_urn,
  ADD COLUMN video_filepath VARCHAR(500) NULL AFTER video_filename;
