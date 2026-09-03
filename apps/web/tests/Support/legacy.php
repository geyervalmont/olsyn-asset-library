<?php

/**
 * A small legacy Material Asset Library: SQLite rows plus a folder tree,
 * shared by the import and corpus ingest tests.
 */
function legacyFixture(string $dir): string
{
    $db = new PDO('sqlite:'.$dir.'/legacy.sqlite');
    $db->exec('CREATE TABLE category (id TEXT, name TEXT, slug TEXT)');
    $db->exec('CREATE TABLE supplier (id TEXT, name TEXT, slug TEXT, website_url TEXT)');
    $db->exec('CREATE TABLE product (id TEXT, supplier_id TEXT, category_id TEXT, name TEXT, slug TEXT, supplier_product_code TEXT, collection_name TEXT, description TEXT, source_url TEXT, product_status TEXT, current_product_path TEXT, sqm_cost REAL, lead_time TEXT, search_tags TEXT)');
    $db->exec('CREATE TABLE product_generation_context (product_id TEXT, material_type TEXT, material_form TEXT, module_width_mm REAL, module_height_mm REAL, module_thickness_mm REAL, joint_spacing_mm REAL, repeat_width_mm REAL, repeat_height_mm REAL, install_pattern TEXT, finish_notes TEXT, surface_notes TEXT, scale_text TEXT, module_text TEXT, product_description TEXT)');
    $db->exec('CREATE TABLE material_variant (id TEXT, product_id TEXT, canonical_key TEXT, colourway_name TEXT, colourway_slug TEXT, colourway_code TEXT, supplier_colour_code TEXT, supplier_colour_name TEXT, finish_name TEXT, install_variant TEXT, scale_width_mm REAL, scale_height_mm REAL, repeat_type TEXT)');
    $db->exec('CREATE TABLE material_variant_metadata (material_variant_id TEXT, colour_family TEXT, dominant_hex TEXT, colour_description TEXT, info_tags TEXT)');
    $db->exec('CREATE TABLE asset_file (id TEXT, material_variant_id TEXT, product_id TEXT, channel TEXT, asset_role TEXT, asset_state TEXT, relative_path TEXT, width_px INTEGER, height_px INTEGER)');
    $db->exec('CREATE TABLE asset_derivation (output_asset_file_id TEXT, derivation_type TEXT, tool_name TEXT, tool_version TEXT)');
    $db->exec('CREATE TABLE asset_source (asset_file_id TEXT, source_type TEXT, source_url TEXT, source_page_url TEXT, license_notes TEXT)');

    $db->exec("INSERT INTO category VALUES ('c1','Carpet','carpet'), ('c2','Vinyl_Flooring','vinyl_flooring'), ('c3','_Archived_Historical','archived')");
    $db->exec("INSERT INTO supplier VALUES ('s1','Tarkett','tarkett','https://www.tarkett.com'), ('s2','Forbo','forbo','https://www.forbo.com')");
    $db->exec("INSERT INTO product VALUES ('p1','s1','c1','Academix','academix','634014','Academix','Loop pile carpet tile.','https://tarkett.example/academix','active','C:\\\\Library\\\\Carpet\\\\Tarkett\\\\Academix',42.5,'Instock','Carpet, Tarkett, Academix, commercial, loop pile, The')");
    $db->exec("INSERT INTO product VALUES ('p2','s2','c2','Allura','allura',NULL,NULL,NULL,'','active','C:\\\\Library\\\\Vinyl_Flooring\\\\Forbo\\\\Allura',NULL,NULL,'')");
    $db->exec("INSERT INTO product VALUES ('p3','s2','c3','Old Vinyl','old_vinyl',NULL,NULL,NULL,'','active','C:\\\\Library\\\\_Archived_Historical\\\\Old Vinyl',NULL,NULL,'')");
    $db->exec("INSERT INTO product_generation_context VALUES ('p1','carpet tile','tile',500,500,6.5,NULL,NULL,NULL,'Monolithic',NULL,NULL,'500 x 500 mm','Tile',NULL)");
    $db->exec("INSERT INTO material_variant VALUES ('v1','p1','carpet:tarkett:academix:634014001:ashen','Ashen 001','ashen','634014001','001','ASHEN',NULL,'',500,500,'tile')");
    $db->exec("INSERT INTO material_variant VALUES ('v2','p1','carpet:tarkett:academix:634014002:slate','Slate','slate','634014002','634014002','SLATE','Matte','EchoPanel.pdf',250,1000,'unknown')");
    $db->exec("INSERT INTO material_variant VALUES ('v3','p2','vinyl_flooring:forbo:allura:na:oak','Oak Allura Oak','oak','na','na','Oak',NULL,'Herringbone',NULL,NULL,'approximate_surface_crop')");
    $db->exec("INSERT INTO material_variant VALUES ('v4','p2','vinyl_flooring:forbo:allura:na:duckegg_base','allura-fr duckegg BASE','duckegg_base','na','na','x',NULL,'',NULL,NULL,'unknown')");
    $db->exec("INSERT INTO material_variant VALUES ('v5','p2','vinyl_flooring:forbo:allura:na:duckegg_nrm','allura-fr duckegg NRM','duckegg_nrm','na','na','x',NULL,'',NULL,NULL,'unknown')");
    $db->exec("INSERT INTO material_variant_metadata VALUES ('v1','grey','#8a8a86','warm grey',NULL), ('v2','grey','bad',NULL,NULL)");
    $db->exec("INSERT INTO asset_file VALUES ('a1','v1','p1','Enscape_Revit','albedo','active','Carpet/Tarkett/Academix/Enscape_Revit/ashen_albedo.png',64,64)");
    $db->exec("INSERT INTO asset_file VALUES ('a2','v1','p1','Enscape_Revit','ref_image','active','Carpet/Tarkett/Academix/Enscape_Revit/ashen_ref.png',64,64)");
    $db->exec("INSERT INTO asset_file VALUES ('a3','v1','p1','AI_Mat','albedo','approved','Carpet/Tarkett/Academix/AI_Mat/gen/job_1/generated/albedo.png',64,64)");
    $db->exec("INSERT INTO asset_file VALUES ('a4','v1','p1','AI_Mat','normal_gl','generated_pending_review','Carpet/Tarkett/Academix/AI_Mat/gen/job_1/generated/normal.png',64,64)");
    $db->exec("INSERT INTO asset_file VALUES ('a5','v1','p1','SS','albedo','active','Carpet/Tarkett/Academix/SS/ashen_ss.png',64,64)");
    $db->exec("INSERT INTO asset_file VALUES ('a6','v2','p1','Enscape_Revit','albedo','archived','Carpet/Tarkett/Academix/Enscape_Revit/slate_old.png',64,64)");
    $db->exec("INSERT INTO asset_file VALUES ('a7','v2','p1','Enscape_Revit','albedo','active','Carpet/Tarkett/Academix/Enscape_Revit/missing.png',64,64)");
    $db->exec("INSERT INTO asset_derivation VALUES ('a3','ai_mat_source_pbr_transfer','codex_seamless_material_worker','2026-05-08')");
    $db->exec("INSERT INTO asset_source VALUES ('a1','supplier','https://tarkett.example/ashen.jpg','https://tarkett.example/academix','supplier image')");

    foreach (['Enscape_Revit/ashen_albedo.png' => 120, 'Enscape_Revit/ashen_ref.png' => 90, 'AI_Mat/gen/job_1/generated/albedo.png' => 130, 'AI_Mat/gen/job_1/generated/normal.png' => 128, 'SS/ashen_ss.png' => 100] as $path => $grey) {
        $full = $dir.'/files/Carpet/Tarkett/Academix/'.$path;
        @mkdir(dirname($full), 0777, true);
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 64, 64, imagecolorallocate($image, $grey, $grey, $grey));
        imagepng($image, $full);
    }

    return $dir;
}
