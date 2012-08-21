#!/bin/bash
install_dir="${WEBENABLED_BASE_DIR:-/opt/webenabled}" # default install dir. can be overwritten with -d

shopt -s expand_aliases
set -x

. files/opt/webenabled/backend-scripts/lib/variables || \
  { echo "Error. Unable to load variables"; exit 1; }

. files/opt/webenabled/backend-scripts/lib/functions || \
  { echo "Error. Unable to load functions"; exit 1; }

usage() {
  local prog="$1"
  echo "

Usage: $prog <-d install_directory>

  Options:
    -L distro         Assume the specified distro, don't try to auto-detect
    -d directory      Install the software in the specified directory
    -h                Displays this help message

"
  exit 1
}

install_ce_software() {
  local linux_distro="$1"
  local install_dir="$2"

  cp -a "files/opt/webenabled" $(dirname "$install_dir" )
  chmod go+rx "$install_dir"

  ln -snf os.$linux_distro "$install_dir"/config/os

  mkdir -p /home/clients/websites /home/clients/databases
  chmod 0755 /home/clients /home/clients/websites /home/clients/databases
  ln -snf "$install_dir"/compat/w_ /home/clients/websites/w_
  chown -R w_: "$install_dir"/compat/w_
  chgrp `cat "$install_dir"/config/os/names/apache.group` "$install_dir"/compat/w_
  chgrp `cat "$install_dir"/config/os/names/apache.group` "$install_dir"/compat/w_/public_html
  chgrp `cat "$install_dir"/config/os/names/apache.group` "$install_dir"/compat/w_/public_html/cgi

  mv -f "$_suexec_bin" "$_suexec_bin.dist" || true
  cp "$install_dir"/config/os/pathnames/sbin/suexec "$_suexec_bin"
  chown 0:`cat "$install_dir"/config/os/names/apache.group` "$_suexec_bin"
  chgrp `cat "$install_dir"/config/os/names/apache.group` "$install_dir"/compat/suexec
  chgrp `cat "$install_dir"/config/os/names/apache.group` "$install_dir"/config/os/pathnames/sbin/suexec
  chmod 4710 "$_suexec_bin"
  dd bs=65536 count=1 if=/dev/zero of="$install_dir"/compat/suexec/config/suexec.map
  chmod 600       "$install_dir"/compat/suexec/config/suexec.map
  chown 0:0 "$install_dir"/compat/suexec/config/suexec.map


  mkdir -p `readlink -m "$install_dir"/config/os/pathnames/etc/ssl/certs`
  mkdir -p `readlink -m "$install_dir"/config/os/pathnames/etc/ssl/keys`
  #openssl req -subj "/C=--/ST=SomeState/L=SomeCity/O=SomeOrganization/OU=SomeOrganizationalUnit/CN=*.`hostname`" -new -x509 -days 3650 -nodes -out /opt/webenabled/config/os/pathnames/etc/ssl/certs/wildcard -keyout /opt/webenabled/config/os/pathnames/etc/ssl/keys/wildcard
  cp -a files/cloudenabled/wildcard.cloudenabled.net.key "$install_dir"/config/os/pathnames/etc/ssl/keys/wildcard
  cp -a files/cloudenabled/wildcard.cloudenabled.net.crt "$install_dir"/config/os/pathnames/etc/ssl/certs/wildcard

  echo Vhost-simple-SSL-wildcard > "$install_dir"/config/names/apache-macro
}

add_custom_users_n_groups() {
  local install_dir="$1"

  groupadd virtwww || true
  groupadd weadmin || true

  useradd -s "$install_dir"/current/libexec/server  -u0 -g0 -mo r_we || error

  mkdir ~r_we/.ssh || true
  chmod 700 ~r_we/.ssh
  cat files/opt/webenabled/config/ssh/global.pub >>~r_we/.ssh/authorized_keys
  chmod 600 ~r_we/.ssh/authorized_keys

  groupadd w_
  useradd -M -d /home/clients/websites/w_ -G w_ -g virtwww w_
    # without the -M option, Fedora will create HOME --> ????
}

# main

getopt_flags="hL:d:"

while getopts $getopt_flags OPTS; do
  case "$OPTS" in
    d)
      install_dir="$OPTARG"
      ;;
    L)
      linux_distro="$OPTARG"
      ;;
    h|?)
      usage
      ;;
  esac
done
[ -n "$OPTIND" -a $OPTIND -gt 1 ] && shift $(( $OPTIND - 1 )) 

if [ -z "$install_dir" ]; then
  error "please specify the target installation directory with the -d option"
fi

if [ -e "$install_dir/config/os" ]; then
  error "this software seems to be already installed. To reinstall, please clean up the previous installation."
fi

if [ -z "$linux_distro" ]; then
  linux_distro=$(auto_detect_distro)
  status=$?
  if [ $status -ne 0 ]; then
    error "unable to detect linux distribution. If you know the distro, try using the -L option"
  fi
fi

distro_install_script="install.$linux_distro.sh"
if [ ! -e "$distro_install_script" ]; then
  error "install script '$distro_install_script' is missing"
elif [ ! -f "$distro_install_script" ]; then
  error "'$distro_install_script' is not a regular file"
fi

. "$distro_install_script"
status=$?
if [ $status -ne 0 ]; then
  error "problems in script '$distro_install_script'"
fi

for func in set_variables pre_run; do
  if [ "$(type -t ${linux_distro}_$func)" == "function" ]; then
    ${linux_distro}_$func
    status=$?
    [ $status -ne 0 ] && error "${linux_distro}_$func returned $status"
  fi
done

if type -t "${linux_distro}_install_distro_packages" >/dev/null; then
  "${linux_distro}_install_distro_packages" "$install_dir"
fi

add_custom_users_n_groups "$install_dir"

if type -t "${linux_distro}_post_users_n_groups" >/dev/null; then
  "${linux_distro}_post_users_n_groups" "$install_dir"
fi

install_ce_software "$linux_distro" "$install_dir"

if type -t "${linux_distro}_post_software_install" >/dev/null; then
  "${linux_distro}_post_software_install" "$install_dir"
fi

if type -t "${linux_distro}_adjust_system_config" >/dev/null; then
  "${linux_distro}_adjust_system_config" "$install_dir"
fi

target_scripts_dir="$install_dir/backend-scripts-$ce_version"
mv "$install_dir/backend-scripts" "$target_scripts_dir"
rm -f "$install_dir/current"
ln -s "$target_scripts_dir" "$install_dir/current"
