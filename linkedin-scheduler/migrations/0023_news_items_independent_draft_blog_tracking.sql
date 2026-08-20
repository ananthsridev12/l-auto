-- News Studio: a headline could only ever be turned into ONE of a
-- LinkedIn draft OR a blog post — whichever action ran first flipped
-- news_items.status to 'used', which hid the headline from the "Fresh
-- headlines" list entirely (it filters status='new'), taking the OTHER
-- action's button down with it even though nothing about that headline
-- actually prevented doing both.
--
-- Fix: track the two outcomes independently via their own nullable FK
-- (post_id already existed; blog_post_id is new here), and only ever
-- auto-advance status to 'used' once BOTH are set — see
-- includes/news_fetch.php news_generate_draft() and
-- pages/news_studio.php's create_draft/write_blog_post handlers. A
-- headline with just one of the two done stays status='new' so it
-- keeps showing in the list (and stays eligible for the daily
-- auto-draft cron to pick up the other side), with its own action
-- button swapped for a "View ..." link once that particular action is
-- done.
ALTER TABLE news_items
  ADD COLUMN blog_post_id INT NULL AFTER post_id,
  ADD CONSTRAINT fk_news_items_blog_post FOREIGN KEY (blog_post_id) REFERENCES blog_posts(id) ON DELETE SET NULL;
