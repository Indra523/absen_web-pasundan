-- Update username format to user[PIN] for role user
UPDATE users 
SET username = CONCAT('user', pin) 
WHERE role = 'user' 
  AND pin IS NOT NULL 
  AND pin != '' 
  AND (username = pin OR username NOT LIKE 'user%');
