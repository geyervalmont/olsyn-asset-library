# Object storage for the Olsyn Asset Library.
#
# This file belongs in the infrastructure repository, not here. Copy it to
# olsyn-infra/terraform/envs/prod/asset-library.tf and apply from there, so it
# lands in the same state as the rest of the platform:
#
#   cd olsyn-infra/terraform/envs/prod
#   AWS_PROFILE=olsyn terraform init && terraform plan
#   AWS_PROFILE=olsyn terraform apply
#
# If either bucket is created by hand first, adopt it rather than recreating,
# following the convention in nucleus-library.tf:
#
#   terraform import aws_s3_bucket.materials olsyn-prod-materials
#   terraform import aws_s3_bucket.material_corpus olsyn-prod-material-corpus
#
# Project, Environment and ManagedBy tags come from the provider's default_tags
# in backend.tf. Both names sit under the olsyn-prod-* prefix, which the edge
# instance profile in modules/edge/main.tf already grants ListBucket, GetObject,
# PutObject and DeleteObject on, so pods on the edge node need no AWS keys.

# ---------------------------------------------------------------------------
# The library itself: content-addressed material files, written by the control
# plane and read by PrismFS. Private; the application serves every byte through
# an authenticated route so that reads are logged.
# ---------------------------------------------------------------------------

resource "aws_s3_bucket" "materials" {
  bucket = "olsyn-prod-materials"

  tags = {
    Purpose = "asset-library-files"
  }
}

resource "aws_s3_bucket_versioning" "materials" {
  bucket = aws_s3_bucket.materials.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "materials" {
  bucket = aws_s3_bucket.materials.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "materials" {
  bucket = aws_s3_bucket.materials.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_lifecycle_configuration" "materials" {
  bucket = aws_s3_bucket.materials.id

  # Files are content-addressed and never rewritten, so a noncurrent version
  # only appears when something is replaced by mistake. Keep them a month.
  rule {
    id     = "expire-noncurrent"
    status = "Enabled"

    filter {}

    noncurrent_version_expiration {
      noncurrent_days = 30
    }
  }

  # A failed ingest of a large file otherwise leaves parts that are billed
  # but invisible in the console.
  rule {
    id     = "abort-incomplete-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }
}

# ---------------------------------------------------------------------------
# The legacy corpus as staged for ingest: the supplier files exactly as they
# came off the old library, read once by opal:import:legacy-files and then left
# alone. Nothing in the platform writes here except the operator loading it.
# ---------------------------------------------------------------------------

resource "aws_s3_bucket" "material_corpus" {
  bucket = "olsyn-prod-material-corpus"

  tags = {
    Purpose = "asset-library-legacy-corpus"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "material_corpus" {
  bucket = aws_s3_bucket.material_corpus.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "material_corpus" {
  bucket = aws_s3_bucket.material_corpus.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_lifecycle_configuration" "material_corpus" {
  bucket = aws_s3_bucket.material_corpus.id

  # The corpus is read once during ingest and rarely afterwards, so let S3
  # move it down the tiers on its own rather than paying Standard for 75 GB.
  rule {
    id     = "tier-cold-corpus"
    status = "Enabled"

    filter {}

    transition {
      days          = 30
      storage_class = "INTELLIGENT_TIERING"
    }
  }

  rule {
    id     = "abort-incomplete-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }
}

# ---------------------------------------------------------------------------
# PrismFS reads the library from wherever a drive is mounted, which may be a
# workstation or the GPU node, neither of which can reach the instance profile.
# It only ever reads.
#
# Following the convention in nucleus-library.tf, the access key is minted by
# hand rather than by Terraform, so the secret never enters the state file:
#
#   aws iam create-access-key --user-name olsyn-prismfs-ops --profile olsyn
# ---------------------------------------------------------------------------

resource "aws_iam_user" "prismfs_ops" {
  name = "olsyn-prismfs-ops"

  tags = {
    Purpose = "asset-library-prismfs-reader"
  }
}

resource "aws_iam_user_policy" "prismfs_ops" {
  name = "olsyn-prismfs-read"
  user = aws_iam_user.prismfs_ops.name

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect   = "Allow"
        Action   = ["s3:ListBucket"]
        Resource = [aws_s3_bucket.materials.arn]
      },
      {
        Effect   = "Allow"
        Action   = ["s3:GetObject"]
        Resource = ["${aws_s3_bucket.materials.arn}/*"]
      },
    ]
  })
}

# ---------------------------------------------------------------------------
# Outputs. Add these alongside the existing assets_buckets and backups_buckets
# maps in outputs.tf, or leave them here if the file is copied wholesale.
# ---------------------------------------------------------------------------

output "asset_library_buckets" {
  description = "Buckets behind the Olsyn Asset Library."

  value = {
    materials = aws_s3_bucket.materials.bucket
    corpus    = aws_s3_bucket.material_corpus.bucket
  }
}
