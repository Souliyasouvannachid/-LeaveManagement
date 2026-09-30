-- ປັບຂໍ້ມູນຕົວຢ່າງໃນຖານຂໍ້ມູນທີ່ຕິດຕັ້ງແລ້ວໃຫ້ເປັນພາສາລາວ
-- ປ່ຽນສະເພາະຄ່າຕົວຢ່າງເດີມ; ບໍ່ປ່ຽນຂໍ້ມູນທີ່ຜູ້ໃຊ້ແກ້ໄຂແລ້ວ

SET NAMES utf8mb4;
USE leave_management;

UPDATE positions
SET name = CASE name
    WHEN 'System Administrator' THEN 'ຜູ້ດູແລລະບົບ'
    WHEN 'HR Officer' THEN 'ເຈົ້າໜ້າທີ່ບຸກຄະລາກອນ'
    WHEN 'IT Manager' THEN 'ຜູ້ຈັດການດ້ານໄອທີ'
    WHEN 'Software Developer' THEN 'ນັກພັດທະນາຊອບແວ'
    WHEN 'Accountant' THEN 'ນັກບັນຊີ'
    WHEN 'Sales Executive' THEN 'ພະນັກງານຂາຍ'
END
WHERE name IN ('System Administrator', 'HR Officer', 'IT Manager',
               'Software Developer', 'Accountant', 'Sales Executive');

UPDATE settings
SET setting_value = 'Asia/Vientiane'
WHERE setting_key = 'timezone' AND setting_value = 'Asia/Bangkok';

UPDATE settings
SET description = 'ເຂດເວລາຫຼັກຂອງລະບົບ'
WHERE setting_key = 'timezone' AND description = 'Timezone ຫຼັກຂອງລະບົບ';
