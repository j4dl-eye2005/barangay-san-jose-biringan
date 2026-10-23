-- Align existing category labels with the category IDs and labels used by the portal UI.
UPDATE disclosure_category SET category_name = 'Announcements' WHERE category_id = 1;
UPDATE disclosure_category SET category_name = 'Financial Reports' WHERE category_id = 2;
UPDATE disclosure_category SET category_name = 'Projects & Budget' WHERE category_id = 3;
UPDATE disclosure_category SET category_name = 'Meeting Minutes' WHERE category_id = 4;
