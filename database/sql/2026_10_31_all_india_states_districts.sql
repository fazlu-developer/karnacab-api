-- All India states and districts for karnacab_platform.
-- Safe to run more than once. Existing Bihar and Delhi rows are left as they are.
-- Districts already stored under the same state name are skipped.

START TRANSACTION;

INSERT INTO states (code, name, status)
SELECT 'AN', 'Andaman and Nicobar Islands', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'AN' OR name = 'Andaman and Nicobar Islands');

INSERT INTO states (code, name, status)
SELECT 'AP', 'Andhra Pradesh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'AP' OR name = 'Andhra Pradesh');

INSERT INTO states (code, name, status)
SELECT 'AR', 'Arunachal Pradesh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'AR' OR name = 'Arunachal Pradesh');

INSERT INTO states (code, name, status)
SELECT 'AS', 'Assam', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'AS' OR name = 'Assam');

INSERT INTO states (code, name, status)
SELECT 'BR', 'Bihar', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'BR' OR name = 'Bihar');

INSERT INTO states (code, name, status)
SELECT 'CH', 'Chandigarh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'CH' OR name = 'Chandigarh');

INSERT INTO states (code, name, status)
SELECT 'CT', 'Chhattisgarh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'CT' OR name = 'Chhattisgarh');

INSERT INTO states (code, name, status)
SELECT 'DH', 'Dadra and Nagar Haveli and Daman and Diu', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'DH' OR name = 'Dadra and Nagar Haveli and Daman and Diu');

INSERT INTO states (code, name, status)
SELECT 'DL', 'Delhi', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'DL' OR name = 'Delhi');

INSERT INTO states (code, name, status)
SELECT 'GA', 'Goa', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'GA' OR name = 'Goa');

INSERT INTO states (code, name, status)
SELECT 'GJ', 'Gujarat', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'GJ' OR name = 'Gujarat');

INSERT INTO states (code, name, status)
SELECT 'HP', 'Himachal Pradesh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'HP' OR name = 'Himachal Pradesh');

INSERT INTO states (code, name, status)
SELECT 'HR', 'Haryana', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'HR' OR name = 'Haryana');

INSERT INTO states (code, name, status)
SELECT 'JH', 'Jharkhand', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'JH' OR name = 'Jharkhand');

INSERT INTO states (code, name, status)
SELECT 'JK', 'Jammu and Kashmir', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'JK' OR name = 'Jammu and Kashmir');

INSERT INTO states (code, name, status)
SELECT 'KA', 'Karnataka', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'KA' OR name = 'Karnataka');

INSERT INTO states (code, name, status)
SELECT 'KL', 'Kerala', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'KL' OR name = 'Kerala');

INSERT INTO states (code, name, status)
SELECT 'LA', 'Ladakh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'LA' OR name = 'Ladakh');

INSERT INTO states (code, name, status)
SELECT 'LD', 'Lakshadweep', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'LD' OR name = 'Lakshadweep');

INSERT INTO states (code, name, status)
SELECT 'MH', 'Maharashtra', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'MH' OR name = 'Maharashtra');

INSERT INTO states (code, name, status)
SELECT 'ML', 'Meghalaya', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'ML' OR name = 'Meghalaya');

INSERT INTO states (code, name, status)
SELECT 'MN', 'Manipur', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'MN' OR name = 'Manipur');

INSERT INTO states (code, name, status)
SELECT 'MP', 'Madhya Pradesh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'MP' OR name = 'Madhya Pradesh');

INSERT INTO states (code, name, status)
SELECT 'MZ', 'Mizoram', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'MZ' OR name = 'Mizoram');

INSERT INTO states (code, name, status)
SELECT 'NL', 'Nagaland', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'NL' OR name = 'Nagaland');

INSERT INTO states (code, name, status)
SELECT 'OD', 'Odisha', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'OD' OR name = 'Odisha');

INSERT INTO states (code, name, status)
SELECT 'PB', 'Punjab', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'PB' OR name = 'Punjab');

INSERT INTO states (code, name, status)
SELECT 'PY', 'Puducherry', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'PY' OR name = 'Puducherry');

INSERT INTO states (code, name, status)
SELECT 'RJ', 'Rajasthan', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'RJ' OR name = 'Rajasthan');

INSERT INTO states (code, name, status)
SELECT 'SK', 'Sikkim', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'SK' OR name = 'Sikkim');

INSERT INTO states (code, name, status)
SELECT 'TG', 'Telangana', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'TG' OR name = 'Telangana');

INSERT INTO states (code, name, status)
SELECT 'TN', 'Tamil Nadu', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'TN' OR name = 'Tamil Nadu');

INSERT INTO states (code, name, status)
SELECT 'TR', 'Tripura', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'TR' OR name = 'Tripura');

INSERT INTO states (code, name, status)
SELECT 'UK', 'Uttarakhand', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'UK' OR name = 'Uttarakhand');

INSERT INTO states (code, name, status)
SELECT 'UP', 'Uttar Pradesh', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'UP' OR name = 'Uttar Pradesh');

INSERT INTO states (code, name, status)
SELECT 'WB', 'West Bengal', 'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM states WHERE code = 'WB' OR name = 'West Bengal');

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-ANANTAPUR', 'Anantapur', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Anantapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-CHITTOOR', 'Chittoor', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chittoor'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-EASTGODAVARI', 'East Godavari', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Godavari'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-GUNTUR', 'Guntur', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Guntur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-KRISHNA', 'Krishna', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Krishna'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-KURNOOL', 'Kurnool', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kurnool'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-NELLORE', 'Nellore', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nellore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-PRAKASAM', 'Prakasam', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Prakasam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-SRIKAKULAM', 'Srikakulam', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Srikakulam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-VISAKHAPATNAM', 'Visakhapatnam', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Visakhapatnam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-VIZIANAGARAM', 'Vizianagaram', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Vizianagaram'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-WESTGODAVARI', 'West Godavari', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Godavari'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AP-YSRKADAPA', 'YSR Kadapa', 'ACTIVE'
FROM states s
WHERE s.code = 'AP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'YSR Kadapa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-TAWANG', 'Tawang', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tawang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-WESTKAMENG', 'West Kameng', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Kameng'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-EASTKAMENG', 'East Kameng', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Kameng'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-PAPUMPARE', 'Papum Pare', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Papum Pare'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-KURUNGKUMEY', 'Kurung Kumey', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kurung Kumey'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-KRADAADI', 'Kra Daadi', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kra Daadi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-LOWERSUBANSIR', 'Lower Subansiri', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lower Subansiri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-UPPERSUBANSIR', 'Upper Subansiri', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Upper Subansiri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-WESTSIANG', 'West Siang', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Siang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-EASTSIANG', 'East Siang', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Siang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-SIANG', 'Siang', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Siang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-UPPERSIANG', 'Upper Siang', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Upper Siang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-LOWERSIANG', 'Lower Siang', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lower Siang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-LOWERDIBANGVA', 'Lower Dibang Valley', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lower Dibang Valley'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-DIBANGVALLEY', 'Dibang Valley', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dibang Valley'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-ANJAW', 'Anjaw', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Anjaw'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-LOHIT', 'Lohit', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lohit'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-NAMSAI', 'Namsai', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Namsai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-CHANGLANG', 'Changlang', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Changlang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-TIRAP', 'Tirap', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tirap'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AR-LONGDING', 'Longding', 'ACTIVE'
FROM states s
WHERE s.code = 'AR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Longding'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-BAKSA', 'Baksa', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Baksa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-BARPETA', 'Barpeta', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Barpeta'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-BISWANATH', 'Biswanath', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Biswanath'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-BONGAIGAON', 'Bongaigaon', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bongaigaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-CACHAR', 'Cachar', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Cachar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-CHARAIDEO', 'Charaideo', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Charaideo'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-CHIRANG', 'Chirang', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chirang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-DARRANG', 'Darrang', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Darrang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-DHEMAJI', 'Dhemaji', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhemaji'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-DHUBRI', 'Dhubri', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhubri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-DIBRUGARH', 'Dibrugarh', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dibrugarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-GOALPARA', 'Goalpara', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Goalpara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-GOLAGHAT', 'Golaghat', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Golaghat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-HAILAKANDI', 'Hailakandi', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hailakandi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-HOJAI', 'Hojai', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hojai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-JORHAT', 'Jorhat', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jorhat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-KAMRUPMETROPO', 'Kamrup Metropolitan', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kamrup Metropolitan'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-KAMRUP', 'Kamrup', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kamrup'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-KARBIANGLONG', 'Karbi Anglong', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Karbi Anglong'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-KARIMGANJ', 'Karimganj', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Karimganj'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-KOKRAJHAR', 'Kokrajhar', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kokrajhar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-LAKHIMPUR', 'Lakhimpur', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lakhimpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-MAJULI', 'Majuli', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Majuli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-MORIGAON', 'Morigaon', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Morigaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-NAGAON', 'Nagaon', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nagaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-NALBARI', 'Nalbari', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nalbari'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-DIMAHASAO', 'Dima Hasao', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dima Hasao'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-SIVASAGAR', 'Sivasagar', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sivasagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-SONITPUR', 'Sonitpur', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sonitpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-SOUTHSALMARAM', 'South Salmara-Mankachar', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South Salmara-Mankachar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-TINSUKIA', 'Tinsukia', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tinsukia'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-UDALGURI', 'Udalguri', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Udalguri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AS-WESTKARBIANGL', 'West Karbi Anglong', 'ACTIVE'
FROM states s
WHERE s.code = 'AS'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Karbi Anglong'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-ARARIA', 'Araria', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Araria'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-ARWAL', 'Arwal', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Arwal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-AURANGABAD', 'Aurangabad', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Aurangabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-BANKA', 'Banka', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Banka'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-BEGUSARAI', 'Begusarai', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Begusarai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-BHAGALPUR', 'Bhagalpur', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhagalpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-BHOJPUR', 'Bhojpur', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhojpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-BUXAR', 'Buxar', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Buxar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-DARBHANGA', 'Darbhanga', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Darbhanga'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-GAYA', 'Gaya', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gaya'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-GOPALGANJ', 'Gopalganj', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gopalganj'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-JAMUI', 'Jamui', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jamui'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-JEHANABAD', 'Jehanabad', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jehanabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-KATIHAR', 'Katihar', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Katihar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-KHAGARIA', 'Khagaria', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Khagaria'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-KISHANGANJ', 'Kishanganj', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kishanganj'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-LAKHISARAI', 'Lakhisarai', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lakhisarai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-MADHEPURA', 'Madhepura', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Madhepura'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-MADHUBANI', 'Madhubani', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Madhubani'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-MUZAFFARPUR', 'Muzaffarpur', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Muzaffarpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-NALANDA', 'Nalanda', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nalanda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-NAWADA', 'Nawada', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nawada'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-PATNA', 'Patna', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Patna'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-ROHTAS', 'Rohtas', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rohtas'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SAHARSA', 'Saharsa', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Saharsa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SAMASTIPUR', 'Samastipur', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Samastipur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SARAN', 'Saran', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Saran'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SHEIKHPURA', 'Sheikhpura', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sheikhpura'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SHEOHAR', 'Sheohar', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sheohar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SITAMARHI', 'Sitamarhi', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sitamarhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SIWAN', 'Siwan', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Siwan'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-SUPAUL', 'Supaul', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Supaul'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-VAISHALI', 'Vaishali', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Vaishali'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'BR-WESTCHAMPARAN', 'West Champaran', 'ACTIVE'
FROM states s
WHERE s.code = 'BR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Champaran'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CH-CHANDIGARH', 'Chandigarh', 'ACTIVE'
FROM states s
WHERE s.code = 'CH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chandigarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-BALOD', 'Balod', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Balod'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-BALODABAZAR', 'Baloda Bazar', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Baloda Bazar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-BALRAMPUR', 'Balrampur', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Balrampur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-BASTAR', 'Bastar', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bastar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-BEMETARA', 'Bemetara', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bemetara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-BIJAPUR', 'Bijapur', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bijapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-BILASPUR', 'Bilaspur', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bilaspur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-DANTEWADASOUT', 'Dantewada (South Bastar)', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dantewada (South Bastar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-DHAMTARI', 'Dhamtari', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhamtari'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-DURG', 'Durg', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Durg'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-GARIYABAND', 'Gariyaband', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gariyaband'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-JANJGIRCHAMPA', 'Janjgir-Champa', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Janjgir-Champa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-JASHPUR', 'Jashpur', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jashpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-KABIRDHAMKAWA', 'Kabirdham (Kawardha)', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kabirdham (Kawardha)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-KANKERNORTHBA', 'Kanker (North Bastar)', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kanker (North Bastar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-KONDAGAON', 'Kondagaon', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kondagaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-KORBA', 'Korba', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Korba'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-KOREAKORIYA', 'Korea (Koriya)', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Korea (Koriya)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-MAHASAMUND', 'Mahasamund', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mahasamund'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-MUNGELI', 'Mungeli', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mungeli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-NARAYANPUR', 'Narayanpur', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Narayanpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-RAIGARH', 'Raigarh', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Raigarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-RAIPUR', 'Raipur', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Raipur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-RAJNANDGAON', 'Rajnandgaon', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rajnandgaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-SUKMA', 'Sukma', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sukma'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-SURAJPUR', 'Surajpur', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Surajpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'CT-SURGUJA', 'Surguja', 'ACTIVE'
FROM states s
WHERE s.code = 'CT'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Surguja'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-CENTRALDELHI', 'Central Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Central Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-EASTDELHI', 'East Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-NEWDELHI', 'New Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'New Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-NORTHDELHI', 'North Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-NORTHEASTDELH', 'North East Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North East Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-NORTHWESTDELH', 'North West Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North West Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-SHAHDARA', 'Shahdara', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shahdara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-SOUTHDELHI', 'South Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-SOUTHEASTDELH', 'South East Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South East Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-SOUTHWESTDELH', 'South West Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South West Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DL-WESTDELHI', 'West Delhi', 'ACTIVE'
FROM states s
WHERE s.code = 'DL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Delhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GA-NORTHGOA', 'North Goa', 'ACTIVE'
FROM states s
WHERE s.code = 'GA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North Goa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GA-SOUTHGOA', 'South Goa', 'ACTIVE'
FROM states s
WHERE s.code = 'GA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South Goa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-AHMEDABAD', 'Ahmedabad', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ahmedabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-AMRELI', 'Amreli', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Amreli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-ANAND', 'Anand', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Anand'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-ARAVALLI', 'Aravalli', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Aravalli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-BANASKANTHAPA', 'Banaskantha (Palanpur)', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Banaskantha (Palanpur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-BHARUCH', 'Bharuch', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bharuch'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-BHAVNAGAR', 'Bhavnagar', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhavnagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-BOTAD', 'Botad', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Botad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-CHHOTAUDEPUR', 'Chhota Udepur', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chhota Udepur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-DAHOD', 'Dahod', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dahod'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-DANGSAHWA', 'Dangs (Ahwa)', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dangs (Ahwa)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-DEVBHOOMIDWAR', 'Devbhoomi Dwarka', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Devbhoomi Dwarka'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-GANDHINAGAR', 'Gandhinagar', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gandhinagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-GIRSOMNATH', 'Gir Somnath', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gir Somnath'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-JAMNAGAR', 'Jamnagar', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jamnagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-JUNAGADH', 'Junagadh', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Junagadh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-KACHCHH', 'Kachchh', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kachchh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-KHEDANADIAD', 'Kheda (Nadiad)', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kheda (Nadiad)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-MAHISAGAR', 'Mahisagar', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mahisagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-MEHSANA', 'Mehsana', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mehsana'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-MORBI', 'Morbi', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Morbi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-NARMADARAJPIP', 'Narmada (Rajpipla)', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Narmada (Rajpipla)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-NAVSARI', 'Navsari', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Navsari'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-PANCHMAHALGOD', 'Panchmahal (Godhra)', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Panchmahal (Godhra)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-PATAN', 'Patan', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Patan'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-PORBANDAR', 'Porbandar', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Porbandar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-RAJKOT', 'Rajkot', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rajkot'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-SABARKANTHAHI', 'Sabarkantha (Himmatnagar)', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sabarkantha (Himmatnagar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-SURAT', 'Surat', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Surat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-SURENDRANAGAR', 'Surendranagar', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Surendranagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-TAPIVYARA', 'Tapi (Vyara)', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tapi (Vyara)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-VADODARA', 'Vadodara', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Vadodara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'GJ-VALSAD', 'Valsad', 'ACTIVE'
FROM states s
WHERE s.code = 'GJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Valsad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-AMBALA', 'Ambala', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ambala'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-BHIWANI', 'Bhiwani', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhiwani'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-CHARKHIDADRI', 'Charkhi Dadri', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Charkhi Dadri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-FARIDABAD', 'Faridabad', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Faridabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-FATEHABAD', 'Fatehabad', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Fatehabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-GURGAON', 'Gurgaon', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gurgaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-HISAR', 'Hisar', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hisar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-JHAJJAR', 'Jhajjar', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jhajjar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-JIND', 'Jind', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jind'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-KAITHAL', 'Kaithal', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kaithal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-KARNAL', 'Karnal', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Karnal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-KURUKSHETRA', 'Kurukshetra', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kurukshetra'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-MAHENDRAGARH', 'Mahendragarh', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mahendragarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-MEWAT', 'Mewat', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mewat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-PALWAL', 'Palwal', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Palwal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-PANCHKULA', 'Panchkula', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Panchkula'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-PANIPAT', 'Panipat', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Panipat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-REWARI', 'Rewari', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rewari'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-ROHTAK', 'Rohtak', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rohtak'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-SIRSA', 'Sirsa', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sirsa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-SONIPAT', 'Sonipat', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sonipat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HR-YAMUNANAGAR', 'Yamunanagar', 'ACTIVE'
FROM states s
WHERE s.code = 'HR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Yamunanagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-BILASPUR', 'Bilaspur', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bilaspur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-CHAMBA', 'Chamba', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chamba'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-HAMIRPUR', 'Hamirpur', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hamirpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-KANGRA', 'Kangra', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kangra'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-KINNAUR', 'Kinnaur', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kinnaur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-KULLU', 'Kullu', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kullu'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-LAHAULSPITI', 'Lahaul & Spiti', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lahaul & Spiti'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-MANDI', 'Mandi', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mandi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-SHIMLA', 'Shimla', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shimla'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-SIRMAURSIRMOU', 'Sirmaur (Sirmour)', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sirmaur (Sirmour)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-SOLAN', 'Solan', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Solan'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'HP-UNA', 'Una', 'ACTIVE'
FROM states s
WHERE s.code = 'HP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Una'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-ANANTNAG', 'Anantnag', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Anantnag'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-BANDIPORE', 'Bandipore', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bandipore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-BARAMULLA', 'Baramulla', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Baramulla'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-BUDGAM', 'Budgam', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Budgam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-DODA', 'Doda', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Doda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-GANDERBAL', 'Ganderbal', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ganderbal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-JAMMU', 'Jammu', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jammu'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-KATHUA', 'Kathua', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kathua'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-KISHTWAR', 'Kishtwar', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kishtwar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-KULGAM', 'Kulgam', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kulgam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-KUPWARA', 'Kupwara', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kupwara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-POONCH', 'Poonch', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Poonch'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-PULWAMA', 'Pulwama', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pulwama'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-RAJOURI', 'Rajouri', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rajouri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-RAMBAN', 'Ramban', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ramban'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-REASI', 'Reasi', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Reasi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-SAMBA', 'Samba', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Samba'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-SHOPIAN', 'Shopian', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shopian'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-SRINAGAR', 'Srinagar', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Srinagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JK-UDHAMPUR', 'Udhampur', 'ACTIVE'
FROM states s
WHERE s.code = 'JK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Udhampur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-BOKARO', 'Bokaro', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bokaro'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-CHATRA', 'Chatra', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chatra'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-DEOGHAR', 'Deoghar', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Deoghar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-DHANBAD', 'Dhanbad', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhanbad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-DUMKA', 'Dumka', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dumka'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-EASTSINGHBHUM', 'East Singhbhum', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Singhbhum'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-GARHWA', 'Garhwa', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Garhwa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-GIRIDIH', 'Giridih', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Giridih'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-GODDA', 'Godda', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Godda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-GUMLA', 'Gumla', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gumla'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-HAZARIBAG', 'Hazaribag', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hazaribag'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-JAMTARA', 'Jamtara', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jamtara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-KHUNTI', 'Khunti', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Khunti'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-KODERMA', 'Koderma', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Koderma'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-LATEHAR', 'Latehar', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Latehar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-LOHARDAGA', 'Lohardaga', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lohardaga'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-PAKUR', 'Pakur', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pakur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-PALAMU', 'Palamu', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Palamu'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-RAMGARH', 'Ramgarh', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ramgarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-RANCHI', 'Ranchi', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ranchi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-SAHIBGANJ', 'Sahibganj', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sahibganj'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-SERAIKELAKHAR', 'Seraikela-Kharsawan', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Seraikela-Kharsawan'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-SIMDEGA', 'Simdega', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Simdega'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'JH-WESTSINGHBHUM', 'West Singhbhum', 'ACTIVE'
FROM states s
WHERE s.code = 'JH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Singhbhum'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-BAGALKOT', 'Bagalkot', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bagalkot'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-BALLARIBELLAR', 'Ballari (Bellary)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ballari (Bellary)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-BELAGAVIBELGA', 'Belagavi (Belgaum)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Belagavi (Belgaum)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-BENGALURUBANG', 'Bengaluru (Bangalore) Rural', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bengaluru (Bangalore) Rural'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-BENGALURUBAN2', 'Bengaluru (Bangalore) Urban', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bengaluru (Bangalore) Urban'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-BIDAR', 'Bidar', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bidar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-CHAMARAJANAGA', 'Chamarajanagar', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chamarajanagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-CHIKBALLAPUR', 'Chikballapur', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chikballapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-CHIKKAMAGALUR', 'Chikkamagaluru (Chikmagalur)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chikkamagaluru (Chikmagalur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-CHITRADURGA', 'Chitradurga', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chitradurga'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-DAKSHINAKANNA', 'Dakshina Kannada', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dakshina Kannada'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-DAVANGERE', 'Davangere', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Davangere'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-DHARWAD', 'Dharwad', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dharwad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-GADAG', 'Gadag', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gadag'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-HASSAN', 'Hassan', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hassan'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-HAVERI', 'Haveri', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Haveri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-KALABURAGIGUL', 'Kalaburagi (Gulbarga)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kalaburagi (Gulbarga)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-KODAGU', 'Kodagu', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kodagu'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-KOLAR', 'Kolar', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kolar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-KOPPAL', 'Koppal', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Koppal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-MANDYA', 'Mandya', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mandya'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-MYSURUMYSORE', 'Mysuru (Mysore)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mysuru (Mysore)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-RAICHUR', 'Raichur', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Raichur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-RAMANAGARA', 'Ramanagara', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ramanagara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-SHIVAMOGGASHI', 'Shivamogga (Shimoga)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shivamogga (Shimoga)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-TUMAKURUTUMKU', 'Tumakuru (Tumkur)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tumakuru (Tumkur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-UDUPI', 'Udupi', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Udupi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-UTTARAKANNADA', 'Uttara Kannada (Karwar)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Uttara Kannada (Karwar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-VIJAYAPURABIJ', 'Vijayapura (Bijapur)', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Vijayapura (Bijapur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KA-YADGIR', 'Yadgir', 'ACTIVE'
FROM states s
WHERE s.code = 'KA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Yadgir'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-ALAPPUZHA', 'Alappuzha', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Alappuzha'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-ERNAKULAM', 'Ernakulam', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ernakulam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-IDUKKI', 'Idukki', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Idukki'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-KANNUR', 'Kannur', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kannur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-KASARAGOD', 'Kasaragod', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kasaragod'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-KOLLAM', 'Kollam', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kollam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-KOTTAYAM', 'Kottayam', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kottayam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-KOZHIKODE', 'Kozhikode', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kozhikode'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-MALAPPURAM', 'Malappuram', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Malappuram'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-PALAKKAD', 'Palakkad', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Palakkad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-PATHANAMTHITT', 'Pathanamthitta', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pathanamthitta'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-THIRUVANANTHA', 'Thiruvananthapuram', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Thiruvananthapuram'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-THRISSUR', 'Thrissur', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Thrissur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'KL-WAYANAD', 'Wayanad', 'ACTIVE'
FROM states s
WHERE s.code = 'KL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Wayanad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-AGATTI', 'Agatti', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Agatti'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-AMINI', 'Amini', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Amini'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-ANDROTH', 'Androth', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Androth'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-BITHRA', 'Bithra', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bithra'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-CHETHLATH', 'Chethlath', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chethlath'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-KAVARATTI', 'Kavaratti', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kavaratti'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-KADMATH', 'Kadmath', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kadmath'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-KALPENI', 'Kalpeni', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kalpeni'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-KILTHAN', 'Kilthan', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kilthan'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LD-MINICOY', 'Minicoy', 'ACTIVE'
FROM states s
WHERE s.code = 'LD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Minicoy'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-AGARMALWA', 'Agar Malwa', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Agar Malwa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-ALIRAJPUR', 'Alirajpur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Alirajpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-ANUPPUR', 'Anuppur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Anuppur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-ASHOKNAGAR', 'Ashoknagar', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ashoknagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-BALAGHAT', 'Balaghat', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Balaghat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-BARWANI', 'Barwani', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Barwani'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-BETUL', 'Betul', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Betul'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-BHIND', 'Bhind', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhind'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-BHOPAL', 'Bhopal', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhopal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-BURHANPUR', 'Burhanpur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Burhanpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-CHHATARPUR', 'Chhatarpur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chhatarpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-CHHINDWARA', 'Chhindwara', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chhindwara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-DAMOH', 'Damoh', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Damoh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-DATIA', 'Datia', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Datia'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-DEWAS', 'Dewas', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dewas'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-DHAR', 'Dhar', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-DINDORI', 'Dindori', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dindori'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-GUNA', 'Guna', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Guna'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-GWALIOR', 'Gwalior', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gwalior'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-HARDA', 'Harda', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Harda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-HOSHANGABAD', 'Hoshangabad', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hoshangabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-INDORE', 'Indore', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Indore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-JABALPUR', 'Jabalpur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jabalpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-JHABUA', 'Jhabua', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jhabua'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-KATNI', 'Katni', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Katni'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-KHANDWA', 'Khandwa', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Khandwa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-KHARGONE', 'Khargone', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Khargone'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-MANDLA', 'Mandla', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mandla'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-MANDSAUR', 'Mandsaur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mandsaur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-MORENA', 'Morena', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Morena'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-NARSINGHPUR', 'Narsinghpur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Narsinghpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-NEEMUCH', 'Neemuch', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Neemuch'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-PANNA', 'Panna', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Panna'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-RAISEN', 'Raisen', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Raisen'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-RAJGARH', 'Rajgarh', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rajgarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-RATLAM', 'Ratlam', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ratlam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-REWA', 'Rewa', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rewa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SAGAR', 'Sagar', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SATNA', 'Satna', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Satna'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SEHORE', 'Sehore', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sehore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SEONI', 'Seoni', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Seoni'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SHAHDOL', 'Shahdol', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shahdol'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SHAJAPUR', 'Shajapur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shajapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SHEOPUR', 'Sheopur', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sheopur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SHIVPURI', 'Shivpuri', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shivpuri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SIDHI', 'Sidhi', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sidhi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-SINGRAULI', 'Singrauli', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Singrauli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-TIKAMGARH', 'Tikamgarh', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tikamgarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-UJJAIN', 'Ujjain', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ujjain'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-UMARIA', 'Umaria', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Umaria'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MP-VIDISHA', 'Vidisha', 'ACTIVE'
FROM states s
WHERE s.code = 'MP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Vidisha'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-AHMEDNAGAR', 'Ahmednagar', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ahmednagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-AKOLA', 'Akola', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Akola'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-AMRAVATI', 'Amravati', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Amravati'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-AURANGABAD', 'Aurangabad', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Aurangabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-BEED', 'Beed', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Beed'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-BHANDARA', 'Bhandara', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhandara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-BULDHANA', 'Buldhana', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Buldhana'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-CHANDRAPUR', 'Chandrapur', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chandrapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-DHULE', 'Dhule', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhule'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-GADCHIROLI', 'Gadchiroli', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gadchiroli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-GONDIA', 'Gondia', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gondia'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-HINGOLI', 'Hingoli', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hingoli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-JALGAON', 'Jalgaon', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jalgaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-JALNA', 'Jalna', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jalna'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-KOLHAPUR', 'Kolhapur', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kolhapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-LATUR', 'Latur', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Latur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-MUMBAICITY', 'Mumbai City', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mumbai City'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-MUMBAISUBURBA', 'Mumbai Suburban', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mumbai Suburban'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-NAGPUR', 'Nagpur', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nagpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-NANDED', 'Nanded', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nanded'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-NANDURBAR', 'Nandurbar', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nandurbar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-NASHIK', 'Nashik', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nashik'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-OSMANABAD', 'Osmanabad', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Osmanabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-PALGHAR', 'Palghar', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Palghar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-PARBHANI', 'Parbhani', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Parbhani'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-PUNE', 'Pune', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pune'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-RAIGAD', 'Raigad', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Raigad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-RATNAGIRI', 'Ratnagiri', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ratnagiri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-SANGLI', 'Sangli', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sangli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-SATARA', 'Satara', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Satara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-SINDHUDURG', 'Sindhudurg', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sindhudurg'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-SOLAPUR', 'Solapur', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Solapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-THANE', 'Thane', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Thane'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-WARDHA', 'Wardha', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Wardha'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-WASHIM', 'Washim', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Washim'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MH-YAVATMAL', 'Yavatmal', 'ACTIVE'
FROM states s
WHERE s.code = 'MH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Yavatmal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-BISHNUPUR', 'Bishnupur', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bishnupur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-CHANDEL', 'Chandel', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chandel'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-CHURACHANDPUR', 'Churachandpur', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Churachandpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-IMPHALEAST', 'Imphal East', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Imphal East'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-IMPHALWEST', 'Imphal West', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Imphal West'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-JIRIBAM', 'Jiribam', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jiribam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-KAKCHING', 'Kakching', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kakching'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-KAMJONG', 'Kamjong', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kamjong'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-KANGPOKPI', 'Kangpokpi', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kangpokpi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-NONEY', 'Noney', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Noney'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-PHERZAWL', 'Pherzawl', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pherzawl'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-SENAPATI', 'Senapati', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Senapati'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-TAMENGLONG', 'Tamenglong', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tamenglong'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-TENGNOUPAL', 'Tengnoupal', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tengnoupal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-THOUBAL', 'Thoubal', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Thoubal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MN-UKHRUL', 'Ukhrul', 'ACTIVE'
FROM states s
WHERE s.code = 'MN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ukhrul'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-EASTGAROHILLS', 'East Garo Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Garo Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-EASTJAINTIAHI', 'East Jaintia Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Jaintia Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-EASTKHASIHILL', 'East Khasi Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Khasi Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-NORTHGAROHILL', 'North Garo Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North Garo Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-RIBHOI', 'Ri Bhoi', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ri Bhoi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-SOUTHGAROHILL', 'South Garo Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South Garo Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-SOUTHWESTGARO', 'South West Garo Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South West Garo Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-SOUTHWESTKHAS', 'South West Khasi Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South West Khasi Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-WESTGAROHILLS', 'West Garo Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Garo Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-WESTJAINTIAHI', 'West Jaintia Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Jaintia Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'ML-WESTKHASIHILL', 'West Khasi Hills', 'ACTIVE'
FROM states s
WHERE s.code = 'ML'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Khasi Hills'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-AIZAWL', 'Aizawl', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Aizawl'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-CHAMPHAI', 'Champhai', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Champhai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-KOLASIB', 'Kolasib', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kolasib'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-LAWNGTLAI', 'Lawngtlai', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lawngtlai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-LUNGLEI', 'Lunglei', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lunglei'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-MAMIT', 'Mamit', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mamit'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-SAIHA', 'Saiha', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Saiha'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'MZ-SERCHHIP', 'Serchhip', 'ACTIVE'
FROM states s
WHERE s.code = 'MZ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Serchhip'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-DIMAPUR', 'Dimapur', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dimapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-KIPHIRE', 'Kiphire', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kiphire'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-KOHIMA', 'Kohima', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kohima'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-LONGLENG', 'Longleng', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Longleng'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-MOKOKCHUNG', 'Mokokchung', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mokokchung'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-MON', 'Mon', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-PEREN', 'Peren', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Peren'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-PHEK', 'Phek', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Phek'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-TUENSANG', 'Tuensang', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tuensang'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-WOKHA', 'Wokha', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Wokha'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'NL-ZUNHEBOTO', 'Zunheboto', 'ACTIVE'
FROM states s
WHERE s.code = 'NL'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Zunheboto'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-ANGUL', 'Angul', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Angul'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-BALANGIR', 'Balangir', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Balangir'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-BALASORE', 'Balasore', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Balasore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-BARGARH', 'Bargarh', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bargarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-BHADRAK', 'Bhadrak', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhadrak'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-BOUDH', 'Boudh', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Boudh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-CUTTACK', 'Cuttack', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Cuttack'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-DEOGARH', 'Deogarh', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Deogarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-DHENKANAL', 'Dhenkanal', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhenkanal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-GAJAPATI', 'Gajapati', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gajapati'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-GANJAM', 'Ganjam', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ganjam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-JAGATSINGHAPU', 'Jagatsinghapur', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jagatsinghapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-JAJPUR', 'Jajpur', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jajpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-JHARSUGUDA', 'Jharsuguda', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jharsuguda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-KALAHANDI', 'Kalahandi', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kalahandi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-KANDHAMAL', 'Kandhamal', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kandhamal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-KENDRAPARA', 'Kendrapara', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kendrapara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-KENDUJHARKEON', 'Kendujhar (Keonjhar)', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kendujhar (Keonjhar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-KHORDHA', 'Khordha', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Khordha'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-KORAPUT', 'Koraput', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Koraput'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-MALKANGIRI', 'Malkangiri', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Malkangiri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-MAYURBHANJ', 'Mayurbhanj', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mayurbhanj'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-NABARANGPUR', 'Nabarangpur', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nabarangpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-NAYAGARH', 'Nayagarh', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nayagarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-NUAPADA', 'Nuapada', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nuapada'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-PURI', 'Puri', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Puri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-RAYAGADA', 'Rayagada', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rayagada'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-SAMBALPUR', 'Sambalpur', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sambalpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-SONEPUR', 'Sonepur', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sonepur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'OD-SUNDARGARH', 'Sundargarh', 'ACTIVE'
FROM states s
WHERE s.code = 'OD'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sundargarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PY-KARAIKAL', 'Karaikal', 'ACTIVE'
FROM states s
WHERE s.code = 'PY'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Karaikal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PY-MAHE', 'Mahe', 'ACTIVE'
FROM states s
WHERE s.code = 'PY'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mahe'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PY-PONDICHERRY', 'Pondicherry', 'ACTIVE'
FROM states s
WHERE s.code = 'PY'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pondicherry'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PY-YANAM', 'Yanam', 'ACTIVE'
FROM states s
WHERE s.code = 'PY'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Yanam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-AMRITSAR', 'Amritsar', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Amritsar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-BARNALA', 'Barnala', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Barnala'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-BATHINDA', 'Bathinda', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bathinda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-FARIDKOT', 'Faridkot', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Faridkot'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-FATEHGARHSAHI', 'Fatehgarh Sahib', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Fatehgarh Sahib'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-FAZILKA', 'Fazilka', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Fazilka'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-FEROZEPUR', 'Ferozepur', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ferozepur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-GURDASPUR', 'Gurdaspur', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gurdaspur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-HOSHIARPUR', 'Hoshiarpur', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hoshiarpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-JALANDHAR', 'Jalandhar', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jalandhar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-KAPURTHALA', 'Kapurthala', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kapurthala'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-LUDHIANA', 'Ludhiana', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ludhiana'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-MANSA', 'Mansa', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mansa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-MOGA', 'Moga', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Moga'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-MUKTSAR', 'Muktsar', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Muktsar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-NAWANSHAHRSHA', 'Nawanshahr (Shahid Bhagat Singh Nagar)', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nawanshahr (Shahid Bhagat Singh Nagar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-PATHANKOT', 'Pathankot', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pathankot'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-PATIALA', 'Patiala', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Patiala'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-RUPNAGAR', 'Rupnagar', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rupnagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-SAHIBZADAAJIT', 'Sahibzada Ajit Singh Nagar (Mohali)', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sahibzada Ajit Singh Nagar (Mohali)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-SANGRUR', 'Sangrur', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sangrur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'PB-TARNTARAN', 'Tarn Taran', 'ACTIVE'
FROM states s
WHERE s.code = 'PB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tarn Taran'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-AJMER', 'Ajmer', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ajmer'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-ALWAR', 'Alwar', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Alwar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-BANSWARA', 'Banswara', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Banswara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-BARAN', 'Baran', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Baran'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-BARMER', 'Barmer', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Barmer'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-BHARATPUR', 'Bharatpur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bharatpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-BHILWARA', 'Bhilwara', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhilwara'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-BIKANER', 'Bikaner', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bikaner'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-BUNDI', 'Bundi', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bundi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-CHITTORGARH', 'Chittorgarh', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chittorgarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-CHURU', 'Churu', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Churu'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-DAUSA', 'Dausa', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dausa'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-DHOLPUR', 'Dholpur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dholpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-DUNGARPUR', 'Dungarpur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dungarpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-HANUMANGARH', 'Hanumangarh', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hanumangarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-JAIPUR', 'Jaipur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jaipur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-JAISALMER', 'Jaisalmer', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jaisalmer'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-JALORE', 'Jalore', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jalore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-JHALAWAR', 'Jhalawar', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jhalawar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-JHUNJHUNU', 'Jhunjhunu', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jhunjhunu'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-JODHPUR', 'Jodhpur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jodhpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-KARAULI', 'Karauli', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Karauli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-KOTA', 'Kota', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kota'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-NAGAUR', 'Nagaur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nagaur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-PALI', 'Pali', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pali'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-PRATAPGARH', 'Pratapgarh', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pratapgarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-RAJSAMAND', 'Rajsamand', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rajsamand'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-SAWAIMADHOPUR', 'Sawai Madhopur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sawai Madhopur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-SIKAR', 'Sikar', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sikar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-SIROHI', 'Sirohi', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sirohi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-SRIGANGANAGAR', 'Sri Ganganagar', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sri Ganganagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-TONK', 'Tonk', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tonk'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'RJ-UDAIPUR', 'Udaipur', 'ACTIVE'
FROM states s
WHERE s.code = 'RJ'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Udaipur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'SK-EASTSIKKIM', 'East Sikkim', 'ACTIVE'
FROM states s
WHERE s.code = 'SK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'East Sikkim'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'SK-NORTHSIKKIM', 'North Sikkim', 'ACTIVE'
FROM states s
WHERE s.code = 'SK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North Sikkim'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'SK-SOUTHSIKKIM', 'South Sikkim', 'ACTIVE'
FROM states s
WHERE s.code = 'SK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South Sikkim'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'SK-WESTSIKKIM', 'West Sikkim', 'ACTIVE'
FROM states s
WHERE s.code = 'SK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Sikkim'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-ARIYALUR', 'Ariyalur', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ariyalur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-CHENNAI', 'Chennai', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chennai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-COIMBATORE', 'Coimbatore', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Coimbatore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-CUDDALORE', 'Cuddalore', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Cuddalore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-DHARMAPURI', 'Dharmapuri', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dharmapuri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-DINDIGUL', 'Dindigul', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dindigul'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-ERODE', 'Erode', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Erode'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-KANCHIPURAM', 'Kanchipuram', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kanchipuram'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-KANYAKUMARI', 'Kanyakumari', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kanyakumari'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-KARUR', 'Karur', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Karur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-KRISHNAGIRI', 'Krishnagiri', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Krishnagiri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-MADURAI', 'Madurai', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Madurai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-NAGAPATTINAM', 'Nagapattinam', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nagapattinam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-NAMAKKAL', 'Namakkal', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Namakkal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-NILGIRIS', 'Nilgiris', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nilgiris'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-PERAMBALUR', 'Perambalur', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Perambalur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-PUDUKKOTTAI', 'Pudukkottai', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pudukkottai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-RAMANATHAPURA', 'Ramanathapuram', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ramanathapuram'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-SALEM', 'Salem', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Salem'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-SIVAGANGA', 'Sivaganga', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sivaganga'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-THANJAVUR', 'Thanjavur', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Thanjavur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-THENI', 'Theni', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Theni'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-THOOTHUKUDITU', 'Thoothukudi (Tuticorin)', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Thoothukudi (Tuticorin)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-TIRUCHIRAPPAL', 'Tiruchirappalli', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tiruchirappalli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-TIRUNELVELI', 'Tirunelveli', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tirunelveli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-TIRUPPUR', 'Tiruppur', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tiruppur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-TIRUVALLUR', 'Tiruvallur', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tiruvallur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-TIRUVANNAMALA', 'Tiruvannamalai', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tiruvannamalai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-TIRUVARUR', 'Tiruvarur', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tiruvarur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-VELLORE', 'Vellore', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Vellore'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-VILUPPURAM', 'Viluppuram', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Viluppuram'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TN-VIRUDHUNAGAR', 'Virudhunagar', 'ACTIVE'
FROM states s
WHERE s.code = 'TN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Virudhunagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-ADILABAD', 'Adilabad', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Adilabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-BHADRADRIKOTH', 'Bhadradri Kothagudem', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhadradri Kothagudem'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-HYDERABAD', 'Hyderabad', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hyderabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-JAGTIAL', 'Jagtial', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jagtial'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-JANGAON', 'Jangaon', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jangaon'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-JAYASHANKARBH', 'Jayashankar Bhoopalpally', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jayashankar Bhoopalpally'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-JOGULAMBAGADW', 'Jogulamba Gadwal', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jogulamba Gadwal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-KAMAREDDY', 'Kamareddy', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kamareddy'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-KARIMNAGAR', 'Karimnagar', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Karimnagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-KHAMMAM', 'Khammam', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Khammam'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-KOMARAMBHEEMA', 'Komaram Bheem Asifabad', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Komaram Bheem Asifabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-MAHABUBABAD', 'Mahabubabad', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mahabubabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-MAHABUBNAGAR', 'Mahabubnagar', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mahabubnagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-MANCHERIAL', 'Mancherial', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mancherial'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-MEDAK', 'Medak', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Medak'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-MEDCHAL', 'Medchal', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Medchal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-NAGARKURNOOL', 'Nagarkurnool', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nagarkurnool'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-NALGONDA', 'Nalgonda', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nalgonda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-NIRMAL', 'Nirmal', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nirmal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-NIZAMABAD', 'Nizamabad', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nizamabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-PEDDAPALLI', 'Peddapalli', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Peddapalli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-RAJANNASIRCIL', 'Rajanna Sircilla', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rajanna Sircilla'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-RANGAREDDY', 'Rangareddy', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rangareddy'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-SANGAREDDY', 'Sangareddy', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sangareddy'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-SIDDIPET', 'Siddipet', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Siddipet'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-SURYAPET', 'Suryapet', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Suryapet'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-VIKARABAD', 'Vikarabad', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Vikarabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-WANAPARTHY', 'Wanaparthy', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Wanaparthy'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-WARANGALRURAL', 'Warangal (Rural)', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Warangal (Rural)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-WARANGALURBAN', 'Warangal (Urban)', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Warangal (Urban)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TG-YADADRIBHUVAN', 'Yadadri Bhuvanagiri', 'ACTIVE'
FROM states s
WHERE s.code = 'TG'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Yadadri Bhuvanagiri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-DHALAI', 'Dhalai', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dhalai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-GOMATI', 'Gomati', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gomati'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-KHOWAI', 'Khowai', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Khowai'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-NORTHTRIPURA', 'North Tripura', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North Tripura'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-SEPAHIJALA', 'Sepahijala', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sepahijala'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-SOUTHTRIPURA', 'South Tripura', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South Tripura'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-UNAKOTI', 'Unakoti', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Unakoti'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'TR-WESTTRIPURA', 'West Tripura', 'ACTIVE'
FROM states s
WHERE s.code = 'TR'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'West Tripura'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-ALMORA', 'Almora', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Almora'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-BAGESHWAR', 'Bageshwar', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bageshwar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-CHAMOLI', 'Chamoli', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chamoli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-CHAMPAWAT', 'Champawat', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Champawat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-DEHRADUN', 'Dehradun', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dehradun'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-HARIDWAR', 'Haridwar', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Haridwar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-NAINITAL', 'Nainital', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nainital'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-PAURIGARHWAL', 'Pauri Garhwal', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pauri Garhwal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-PITHORAGARH', 'Pithoragarh', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pithoragarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-RUDRAPRAYAG', 'Rudraprayag', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rudraprayag'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-TEHRIGARHWAL', 'Tehri Garhwal', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Tehri Garhwal'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-UDHAMSINGHNAG', 'Udham Singh Nagar', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Udham Singh Nagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UK-UTTARKASHI', 'Uttarkashi', 'ACTIVE'
FROM states s
WHERE s.code = 'UK'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Uttarkashi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-AGRA', 'Agra', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Agra'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-ALIGARH', 'Aligarh', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Aligarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-ALLAHABAD', 'Allahabad', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Allahabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-AMBEDKARNAGAR', 'Ambedkar Nagar', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ambedkar Nagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-AMETHICHATRAP', 'Amethi (Chatrapati Sahuji Mahraj Nagar)', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Amethi (Chatrapati Sahuji Mahraj Nagar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-AMROHAJPNAGAR', 'Amroha (J.P. Nagar)', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Amroha (J.P. Nagar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-AURAIYA', 'Auraiya', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Auraiya'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-AZAMGARH', 'Azamgarh', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Azamgarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BAGHPAT', 'Baghpat', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Baghpat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BAHRAICH', 'Bahraich', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bahraich'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BALLIA', 'Ballia', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ballia'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BALRAMPUR', 'Balrampur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Balrampur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BANDA', 'Banda', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Banda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BARABANKI', 'Barabanki', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Barabanki'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BAREILLY', 'Bareilly', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bareilly'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BASTI', 'Basti', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Basti'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BHADOHI', 'Bhadohi', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bhadohi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BIJNOR', 'Bijnor', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bijnor'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BUDAUN', 'Budaun', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Budaun'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-BULANDSHAHR', 'Bulandshahr', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bulandshahr'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-CHANDAULI', 'Chandauli', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chandauli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-CHITRAKOOT', 'Chitrakoot', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Chitrakoot'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-DEORIA', 'Deoria', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Deoria'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-ETAH', 'Etah', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Etah'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-ETAWAH', 'Etawah', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Etawah'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-FAIZABAD', 'Faizabad', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Faizabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-FARRUKHABAD', 'Farrukhabad', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Farrukhabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-FATEHPUR', 'Fatehpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Fatehpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-FIROZABAD', 'Firozabad', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Firozabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-GAUTAMBUDDHAN', 'Gautam Buddha Nagar', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gautam Buddha Nagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-GHAZIABAD', 'Ghaziabad', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ghaziabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-GHAZIPUR', 'Ghazipur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Ghazipur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-GONDA', 'Gonda', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gonda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-GORAKHPUR', 'Gorakhpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Gorakhpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-HAMIRPUR', 'Hamirpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hamirpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-HAPURPANCHSHE', 'Hapur (Panchsheel Nagar)', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hapur (Panchsheel Nagar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-HARDOI', 'Hardoi', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hardoi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-HATHRAS', 'Hathras', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hathras'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-JALAUN', 'Jalaun', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jalaun'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-JAUNPUR', 'Jaunpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jaunpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-JHANSI', 'Jhansi', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jhansi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-KANNAUJ', 'Kannauj', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kannauj'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-KANPURDEHAT', 'Kanpur Dehat', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kanpur Dehat'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-KANPURNAGAR', 'Kanpur Nagar', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kanpur Nagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-KANSHIRAMNAGA', 'Kanshiram Nagar (Kasganj)', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kanshiram Nagar (Kasganj)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-KAUSHAMBI', 'Kaushambi', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kaushambi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-KUSHINAGARPAD', 'Kushinagar (Padrauna)', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kushinagar (Padrauna)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-LAKHIMPURKHER', 'Lakhimpur - Kheri', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lakhimpur - Kheri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-LALITPUR', 'Lalitpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lalitpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-LUCKNOW', 'Lucknow', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Lucknow'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MAHARAJGANJ', 'Maharajganj', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Maharajganj'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MAHOBA', 'Mahoba', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mahoba'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MAINPURI', 'Mainpuri', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mainpuri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MATHURA', 'Mathura', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mathura'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MAU', 'Mau', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mau'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MEERUT', 'Meerut', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Meerut'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MIRZAPUR', 'Mirzapur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Mirzapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MORADABAD', 'Moradabad', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Moradabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-MUZAFFARNAGAR', 'Muzaffarnagar', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Muzaffarnagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-PILIBHIT', 'Pilibhit', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pilibhit'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-PRATAPGARH', 'Pratapgarh', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Pratapgarh'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-RAEBARELI', 'RaeBareli', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'RaeBareli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-RAMPUR', 'Rampur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Rampur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SAHARANPUR', 'Saharanpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Saharanpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SAMBHALBHIMNA', 'Sambhal (Bhim Nagar)', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sambhal (Bhim Nagar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SANTKABIRNAGA', 'Sant Kabir Nagar', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sant Kabir Nagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SHAHJAHANPUR', 'Shahjahanpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shahjahanpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SHAMALIPRABUD', 'Shamali (Prabuddh Nagar)', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shamali (Prabuddh Nagar)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SHRAVASTI', 'Shravasti', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Shravasti'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SIDDHARTHNAGA', 'Siddharth Nagar', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Siddharth Nagar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SITAPUR', 'Sitapur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sitapur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SONBHADRA', 'Sonbhadra', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sonbhadra'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-SULTANPUR', 'Sultanpur', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Sultanpur'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-UNNAO', 'Unnao', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Unnao'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'UP-VARANASI', 'Varanasi', 'ACTIVE'
FROM states s
WHERE s.code = 'UP'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Varanasi'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-ALIPURDUAR', 'Alipurduar', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Alipurduar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-BANKURA', 'Bankura', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Bankura'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-BIRBHUM', 'Birbhum', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Birbhum'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-BURDWANBARDHA', 'Burdwan (Bardhaman)', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Burdwan (Bardhaman)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-COOCHBEHAR', 'Cooch Behar', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Cooch Behar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-DAKSHINDINAJP', 'Dakshin Dinajpur (South Dinajpur)', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dakshin Dinajpur (South Dinajpur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-DARJEELING', 'Darjeeling', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Darjeeling'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-HOOGHLY', 'Hooghly', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Hooghly'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-HOWRAH', 'Howrah', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Howrah'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-JALPAIGURI', 'Jalpaiguri', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Jalpaiguri'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-KALIMPONG', 'Kalimpong', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kalimpong'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-KOLKATA', 'Kolkata', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kolkata'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-MALDA', 'Malda', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Malda'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-MURSHIDABAD', 'Murshidabad', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Murshidabad'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-NADIA', 'Nadia', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nadia'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-NORTH24PARGAN', 'North 24 Parganas', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North 24 Parganas'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-PASCHIMMEDINI', 'Paschim Medinipur (West Medinipur)', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Paschim Medinipur (West Medinipur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-PURBAMEDINIPU', 'Purba Medinipur (East Medinipur)', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Purba Medinipur (East Medinipur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-PURULIA', 'Purulia', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Purulia'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-SOUTH24PARGAN', 'South 24 Parganas', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South 24 Parganas'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'WB-UTTARDINAJPUR', 'Uttar Dinajpur (North Dinajpur)', 'ACTIVE'
FROM states s
WHERE s.code = 'WB'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Uttar Dinajpur (North Dinajpur)'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AN-NICOBAR', 'Nicobar', 'ACTIVE'
FROM states s
WHERE s.code = 'AN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Nicobar'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AN-NORTHANDMIDDL', 'North and Middle Andaman', 'ACTIVE'
FROM states s
WHERE s.code = 'AN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'North and Middle Andaman'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'AN-SOUTHANDAMAN', 'South Andaman', 'ACTIVE'
FROM states s
WHERE s.code = 'AN'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'South Andaman'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DH-DADRAANDNAGAR', 'Dadra and Nagar Haveli', 'ACTIVE'
FROM states s
WHERE s.code = 'DH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Dadra and Nagar Haveli'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DH-DAMAN', 'Daman', 'ACTIVE'
FROM states s
WHERE s.code = 'DH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Daman'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'DH-DIU', 'Diu', 'ACTIVE'
FROM states s
WHERE s.code = 'DH'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Diu'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LA-KARGIL', 'Kargil', 'ACTIVE'
FROM states s
WHERE s.code = 'LA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Kargil'
  );

INSERT INTO districts (state_id, code, name, status)
SELECT s.id, 'LA-LEH', 'Leh', 'ACTIVE'
FROM states s
WHERE s.code = 'LA'
  AND NOT EXISTS (
    SELECT 1 FROM districts d
    WHERE d.state_id = s.id AND d.name = 'Leh'
  );

COMMIT;
