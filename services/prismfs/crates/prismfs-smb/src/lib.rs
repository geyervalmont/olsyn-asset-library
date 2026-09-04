//! Samba configuration boundary for exporting a mounted PrismFS namespace.

use std::path::PathBuf;

use thiserror::Error;

/// Errors in a generated Samba share configuration.
#[derive(Debug, Error)]
pub enum SmbError {
    /// The share name cannot be represented safely in smb.conf.
    #[error("invalid Samba share name: {0}")]
    InvalidShareName(String),
    /// The guest account cannot be represented safely in smb.conf.
    #[error("invalid Samba guest account: {0}")]
    InvalidGuestAccount(String),
    /// The share user cannot be represented safely in smb.conf.
    #[error("invalid Samba user: {0}")]
    InvalidUser(String),
    /// Samba requires an absolute filesystem path.
    #[error("Samba share path must be absolute: {0}")]
    RelativePath(PathBuf),
    /// The share path cannot be represented safely in smb.conf.
    #[error("invalid Samba share path: {0}")]
    InvalidPath(PathBuf),
}

/// Configuration for a read-only Samba facade over PrismFS.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct SambaConfig {
    /// SMB share name visible to clients.
    pub share_name: String,
    /// Absolute path of the mounted PrismFS namespace.
    pub path: PathBuf,
    /// Unix account used for isolated guest-mode development, and the account
    /// file operations run as.
    pub guest_account: String,
    /// When set, only this Samba user may connect (password via smbpasswd);
    /// guest access is off and signing is mandatory.
    pub valid_user: Option<String>,
}

impl SambaConfig {
    /// Creates and validates a read-only guest share configuration.
    pub fn read_only_guest(
        share_name: impl Into<String>,
        path: impl Into<PathBuf>,
        guest_account: impl Into<String>,
    ) -> Result<Self, SmbError> {
        let config = Self {
            share_name: share_name.into(),
            path: path.into(),
            guest_account: guest_account.into(),
            valid_user: None,
        };
        config.validate()?;
        Ok(config)
    }

    /// Creates and validates a read-only share that only `user` may open.
    /// Windows 11 requires SMB signing, which guests cannot do, so this is the
    /// mode a real workstation needs.
    pub fn read_only_user(
        share_name: impl Into<String>,
        path: impl Into<PathBuf>,
        run_as_account: impl Into<String>,
        user: impl Into<String>,
    ) -> Result<Self, SmbError> {
        let config = Self {
            share_name: share_name.into(),
            path: path.into(),
            guest_account: run_as_account.into(),
            valid_user: Some(user.into()),
        };
        config.validate()?;
        Ok(config)
    }

    /// Validates values interpolated into smb.conf.
    pub fn validate(&self) -> Result<(), SmbError> {
        if self.share_name.is_empty()
            || !self.share_name.chars().all(|character| {
                character.is_ascii_alphanumeric() || matches!(character, '-' | '_')
            })
        {
            return Err(SmbError::InvalidShareName(self.share_name.clone()));
        }
        if !self.path.is_absolute() {
            return Err(SmbError::RelativePath(self.path.clone()));
        }
        let path = self
            .path
            .to_str()
            .ok_or_else(|| SmbError::InvalidPath(self.path.clone()))?;
        if path.chars().any(char::is_control) {
            return Err(SmbError::InvalidPath(self.path.clone()));
        }
        if self.guest_account.is_empty()
            || !self.guest_account.chars().all(|character| {
                character.is_ascii_alphanumeric() || matches!(character, '-' | '_')
            })
        {
            return Err(SmbError::InvalidGuestAccount(self.guest_account.clone()));
        }
        if let Some(user) = self.valid_user.as_deref().filter(|user| {
            user.is_empty()
                || !user.chars().all(|character| {
                    character.is_ascii_alphanumeric() || matches!(character, '-' | '_' | '.')
                })
        }) {
            return Err(SmbError::InvalidUser(user.to_owned()));
        }
        Ok(())
    }

    /// Renders a self-contained, read-only standalone-server configuration.
    pub fn render(&self) -> Result<String, SmbError> {
        self.validate()?;
        let path = self.path.display();
        let share = &self.share_name;
        let account = &self.guest_account;
        let rendered = match &self.valid_user {
            None => format!(
                "[global]\n\
                 server role = standalone server\n\
                 map to guest = Bad User\n\
                 guest account = {account}\n\
                 load printers = no\n\
                 disable spoolss = yes\n\
                 server min protocol = SMB2_10\n\
                 logging = stdout\n\
                 log level = 1\n\
                 \n\
                 [{share}]\n\
                 path = {path}\n\
                 browseable = yes\n\
                 read only = yes\n\
                 guest ok = yes\n\
                 guest only = yes\n\
                 force user = {account}\n\
                 store dos attributes = no\n\
                 map readonly = permissions\n"
            ),
            Some(user) => format!(
                "[global]\n\
                 server role = standalone server\n\
                 map to guest = Never\n\
                 restrict anonymous = 2\n\
                 server signing = mandatory\n\
                 load printers = no\n\
                 disable spoolss = yes\n\
                 server min protocol = SMB2_10\n\
                 logging = stdout\n\
                 log level = 1\n\
                 \n\
                 [{share}]\n\
                 path = {path}\n\
                 browseable = yes\n\
                 read only = yes\n\
                 guest ok = no\n\
                 valid users = {user}\n\
                 force user = {account}\n\
                 store dos attributes = no\n\
                 map readonly = permissions\n"
            ),
        };
        Ok(rendered)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn renders_a_read_only_guest_share() {
        let rendered = SambaConfig::read_only_guest("prismfs", "/srv/prismfs", "root")
            .expect("valid config")
            .render()
            .expect("render config");

        assert!(rendered.contains("[prismfs]"));
        assert!(rendered.contains("read only = yes"));
        assert!(rendered.contains("guest only = yes"));
        assert!(rendered.contains("path = /srv/prismfs"));
    }

    #[test]
    fn renders_an_authenticated_share() {
        let rendered = SambaConfig::read_only_user("opal", "/srv/prismfs", "root", "opal")
            .expect("valid config")
            .render()
            .expect("render config");

        assert!(rendered.contains("valid users = opal"));
        assert!(rendered.contains("guest ok = no"));
        assert!(rendered.contains("server signing = mandatory"));
        assert!(!rendered.contains("guest only"));
        assert!(SambaConfig::read_only_user("opal", "/srv/prismfs", "root", "bad user").is_err());
    }

    #[test]
    fn rejects_smb_conf_injection() {
        assert!(SambaConfig::read_only_guest("bad\n[name]", "/srv/prismfs", "root").is_err());
        assert!(SambaConfig::read_only_guest("prismfs", "relative", "root").is_err());
        assert!(SambaConfig::read_only_guest("prismfs", "/srv/bad\n[name]", "root").is_err());
        assert!(SambaConfig::read_only_guest("prismfs", "/srv/prismfs", "bad\nuser").is_err());
    }
}
