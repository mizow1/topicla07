# GitHub アカウント切り替えスクリプト
param(
    [Parameter(Mandatory=$true)]
    [ValidateSet("mizow1", "skunk0915")]
    [string]$Account,
    
    [string]$RepoName = (Split-Path -Leaf (Get-Location))
)

switch ($Account) {
    "mizow1" {
        $username = "mizow1"
        $email = "mizow1@example.com"  # 実際のメールアドレスに変更してください
    }
    "skunk0915" {
        $username = "skunk0915"
        $email = "skunk0915@example.com"  # 実際のメールアドレスに変更してください
    }
}

# ローカルGit設定を変更
git config --local user.name $username
git config --local user.email $email

# リモートURLを変更
git remote set-url origin "https://$username@github.com/$username/$RepoName.git"

Write-Host "アカウントを $Account に切り替えました"
Write-Host "ユーザー名: $username"
Write-Host "メール: $email"
Write-Host "リモートURL: https://$username@github.com/$username/$RepoName.git"